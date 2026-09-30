<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use Test\TestCase;

/**
 * Validates the conditional merge-guard SQL (markMergedIfUnmerged,
 * touchUpdatedAtIfUnmerged, updateIfUnmerged, closeIfOpenAndUnmerged,
 * stampSlaAlertTimestamps) against the real database, not mocks.
 * Rows are created with a scratch ticket number and removed in tearDown.
 */
class MergeGuardSqlIntegrationTest extends TestCase
{
    private TicketMapper $mapper;
    /** @var list<int> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\OC::class) || !isset(\OC::$server)) {
            $this->markTestSkipped('Nextcloud is not bootstrapped');
        }
        $this->mapper = \OC::$server->get(TicketMapper::class);
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

    private function makeTicket(string $suffix): Ticket
    {
        $t = new Ticket();
        $t->setTicketNumber('HD-SCRATCH-' . $suffix . '-' . bin2hex(random_bytes(4)));
        $t->setTitle('Scratch ' . $suffix);
        $t->setDescription('Scratch merge SQL guard test');
        $t->setCustomerEmail('scratch@example.invalid');
        $t->setCustomerName('Scratch Tester');
        $t->setCategory(Ticket::CATEGORY_GENERAL);
        $t->setPriority(Ticket::PRIORITY_NORMAL);
        $t->setStatus(Ticket::STATUS_NEW);
        $t->setCreatedAt(new \DateTime());
        $t->setUpdatedAt(new \DateTime());
        $t->setCreatedBy('scratch-test');
        $t->setCreatedByGuest(false);
        $saved = $this->mapper->insert($t);
        $this->createdIds[] = (int) $saved->getId();
        return $saved;
    }

    public function testConditionalGuardsAgainstRealDatabase(): void
    {
        $source = $this->makeTicket('S');
        $target = $this->makeTicket('T');
        $s = (int) $source->getId();
        $t = (int) $target->getId();

        // 1. markMergedIfUnmerged wins exactly once.
        self::assertTrue($this->mapper->markMergedIfUnmerged($s, $t), 'first merge claim must win');
        self::assertFalse($this->mapper->markMergedIfUnmerged($s, $t), 'second merge claim must lose');

        // 2. touch guards: merged source refuses, survivor accepts.
        self::assertFalse($this->mapper->touchUpdatedAtIfUnmerged($s), 'merged source must not be touched');
        self::assertTrue($this->mapper->touchUpdatedAtIfUnmerged($t), 'unmerged survivor must be touchable');

        // 3. updateIfUnmerged: dirty update on merged row must be rejected.
        $mergedRow = $this->mapper->find($s);
        $mergedRow->setTitle('should never persist');
        self::assertFalse($this->mapper->updateIfUnmerged($mergedRow), 'merged row update must be rejected');
        self::assertSame('Scratch S', $this->mapper->find($s)->getTitle(), 'merged row title must be unchanged');

        $survivorRow = $this->mapper->find($t);
        $survivorRow->setTitle('survivor updated');
        self::assertTrue($this->mapper->updateIfUnmerged($survivorRow), 'survivor update must persist');
        self::assertSame('survivor updated', $this->mapper->find($t)->getTitle());

        // 4. closeIfOpenAndUnmerged: open survivor closes once, then refuses.
        self::assertTrue($this->mapper->closeIfOpenAndUnmerged($t), 'open survivor must close');
        self::assertFalse($this->mapper->closeIfOpenAndUnmerged($t), 'already-closed ticket must refuse');
        self::assertFalse($this->mapper->closeIfOpenAndUnmerged($s), 'merged row must refuse close');
        $closed = $this->mapper->find($t);
        self::assertSame(Ticket::STATUS_DONE, $closed->getStatus());
        self::assertNotNull($closed->getClosedAt());

        // 5. SLA stamp writes only when asked.
        self::assertFalse($this->mapper->stampSlaAlertTimestamps($t, false, false, new \DateTimeImmutable()));
        self::assertTrue($this->mapper->stampSlaAlertTimestamps($t, true, false, new \DateTimeImmutable()));
        self::assertNotNull($this->mapper->find($t)->getSlaResponseAlertedAt());
        self::assertNull($this->mapper->find($t)->getSlaResolutionAlertedAt());
    }
}
