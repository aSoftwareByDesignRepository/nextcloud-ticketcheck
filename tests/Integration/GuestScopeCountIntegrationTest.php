<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use Test\TestCase;

/**
 * Validates TicketMapper::countGuestScope against the real database.
 *
 * The query must mirror PermissionService::canViewTicketWithProjectAccess for
 * guests: own tickets (created_by) without a project or in accessible
 * projects, plus tickets addressed to the guest's customer_email inside
 * accessible projects. Rows are created with unique scratch markers and
 * removed in tearDown.
 */
class GuestScopeCountIntegrationTest extends TestCase
{
    private TicketMapper $mapper;
    /** @var list<int> */
    private array $createdIds = [];

    private string $guestUid;
    private string $guestEmail;
    /** Project the guest may access. */
    private int $projectA;
    /** Project the guest may NOT access. */
    private int $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\OC::class) || !isset(\OC::$server)) {
            $this->markTestSkipped('Nextcloud is not bootstrapped');
        }
        $this->mapper = \OC::$server->get(TicketMapper::class);

        $tag = bin2hex(random_bytes(4));
        $this->guestUid = 'scratch-guest-' . $tag;
        $this->guestEmail = 'scratch-' . $tag . '@example.invalid';
        $this->projectA = 987600001;
        $this->projectB = 987600002;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            try {
                $this->mapper->delete($this->mapper->find($id));
            } catch (\Throwable $e) {
                // best effort cleanup
            }
        }
        parent::tearDown();
    }

    private function makeTicket(array $fields): Ticket
    {
        $t = new Ticket();
        $t->setTicketNumber('HD-SCOPE-' . bin2hex(random_bytes(4)));
        $t->setTitle('Scratch guest-scope test');
        $t->setDescription('Scratch guest-scope test');
        $t->setCustomerEmail($fields['customer_email'] ?? 'other@example.invalid');
        $t->setCustomerName('Scratch Tester');
        $t->setCategory(Ticket::CATEGORY_GENERAL);
        $t->setPriority(Ticket::PRIORITY_NORMAL);
        $t->setStatus($fields['status'] ?? Ticket::STATUS_NEW);
        $t->setCreatedAt(new \DateTime());
        $t->setUpdatedAt(new \DateTime());
        $t->setCreatedBy($fields['created_by'] ?? 'scratch-staff');
        $t->setCreatedByGuest(false);
        $t->setProjectId($fields['project_id'] ?? null);
        $saved = $this->mapper->insert($t);
        $this->createdIds[] = (int) $saved->getId();
        return $saved;
    }

    public function testGuestScopeCountsOnlyGuestVisibleTickets(): void
    {
        // Visible: addressed to guest inside accessible project.
        $this->makeTicket(['project_id' => $this->projectA, 'customer_email' => $this->guestEmail]);
        // Visible: guest-created inside accessible project.
        $this->makeTicket(['project_id' => $this->projectA, 'created_by' => $this->guestUid]);
        // Visible: guest-created without project.
        $this->makeTicket(['project_id' => null, 'created_by' => $this->guestUid]);

        // Hidden: other customer's ticket in the accessible project.
        $this->makeTicket(['project_id' => $this->projectA, 'customer_email' => 'other@example.invalid']);
        // Hidden: addressed to guest but in an inaccessible project.
        $this->makeTicket(['project_id' => $this->projectB, 'customer_email' => $this->guestEmail]);
        // Hidden: guest-created but in an inaccessible project.
        $this->makeTicket(['project_id' => $this->projectB, 'created_by' => $this->guestUid]);
        // Hidden: no-project ticket not created by the guest (even when addressed).
        $this->makeTicket(['project_id' => null, 'customer_email' => $this->guestEmail]);

        $total = $this->mapper->countGuestScope(
            $this->guestUid,
            $this->guestEmail,
            [$this->projectA],
            false
        );
        $this->assertSame(3, $total, 'guest scope must match portal visibility');

        $open = $this->mapper->countGuestScope(
            $this->guestUid,
            $this->guestEmail,
            [$this->projectA],
            true
        );
        $this->assertSame(3, $open, 'all visible tickets are new -> open');
    }

    public function testGuestScopeOpenExcludesDoneAndLegacyAliases(): void
    {
        $this->makeTicket(['project_id' => $this->projectA, 'customer_email' => $this->guestEmail, 'status' => Ticket::STATUS_NEW]);
        $this->makeTicket(['project_id' => $this->projectA, 'customer_email' => $this->guestEmail, 'status' => Ticket::STATUS_DONE]);
        // Legacy row: raw 'resolved' must also count as done.
        $this->makeTicket(['project_id' => $this->projectA, 'customer_email' => $this->guestEmail, 'status' => 'resolved']);

        $this->assertSame(
            3,
            $this->mapper->countGuestScope($this->guestUid, $this->guestEmail, [$this->projectA], false),
            'total counts every visible ticket'
        );
        $this->assertSame(
            1,
            $this->mapper->countGuestScope($this->guestUid, $this->guestEmail, [$this->projectA], true),
            'open excludes done + legacy done aliases'
        );
    }

    public function testGuestScopeWithoutProjectsCountsOnlyOwnNoProjectTickets(): void
    {
        $this->makeTicket(['project_id' => null, 'created_by' => $this->guestUid]);
        $this->makeTicket(['project_id' => $this->projectA, 'customer_email' => $this->guestEmail]);
        $this->makeTicket(['project_id' => null, 'customer_email' => $this->guestEmail]);

        $this->assertSame(
            1,
            $this->mapper->countGuestScope($this->guestUid, $this->guestEmail, [], false),
            'empty project list leaves only own no-project tickets'
        );
    }

    public function testGuestScopeEmptyIdentityReturnsZero(): void
    {
        $this->assertSame(0, $this->mapper->countGuestScope('', '', [$this->projectA], false));
        $this->assertSame(0, $this->mapper->countGuestScope('', $this->guestEmail, [$this->projectA], false));
        $this->assertSame(0, $this->mapper->countGuestScope($this->guestUid, '', [$this->projectA], false));
    }
}
