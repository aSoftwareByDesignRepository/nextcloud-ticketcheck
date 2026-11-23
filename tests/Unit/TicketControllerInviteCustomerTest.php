<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\TicketController;
use OCA\Ticketcheck\Db\GuestIdentity;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\GuestIdentityService;
use OCA\Ticketcheck\Service\GuestInviteService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use Test\TestCase;

/**
 * NH-01: inviteCustomer — account-less customer_email → identity +
 * project grant + magic-link invite; account-exists and invalid mail gated.
 */
final class TicketControllerInviteCustomerTest extends TestCase
{
    private function controller(array $deps): TicketController
    {
        $ref = new \ReflectionClass(TicketController::class);
        $args = [];
        foreach ($ref->getConstructor()->getParameters() as $p) {
            $t = (string)$p->getType();
            if (str_contains($t, 'string')) {
                $args[] = 'ticketcheck';
            } elseif (str_contains($t, 'PermissionService')) {
                $args[] = $deps['perm'];
            } elseif (str_contains($t, 'IRequest')) {
                $args[] = $deps['request'];
            } elseif (str_contains($t, 'IUserManager')) {
                $args[] = $deps['userManager'];
            } elseif (str_contains($t, 'TicketMapper')) {
                $args[] = $deps['ticketMapper'];
            } elseif (str_contains($t, 'GuestIdentityService')) {
                $args[] = $deps['identities'];
            } elseif (str_contains($t, 'GuestInviteService')) {
                $args[] = $deps['invite'];
            } elseif (str_contains($t, 'IFactory')) {
                $lf = $this->createStub(\OCP\L10N\IFactory::class);
                $l = $this->createStub(IL10N::class);
                $l->method('t')->willReturnCallback(static fn (string $k) => $k);
                $lf->method('get')->willReturn($l);
                $args[] = $lf;
            } else {
                $args[] = $p->allowsNull() ? null : $this->createStub($t);
            }
        }
        return $ref->newInstanceArgs($args);
    }

    private function deps(string $email = 'ext@customer.de'): array
    {
        $ticket = new Ticket();
        $ticket->setId(9);
        $ticket->setCustomerEmail($email);
        $ticket->setCustomerName('Ext');
        $ticket->setProjectId(77);
        $tm = $this->createStub(TicketMapper::class);
        $tm->method('find')->willReturn($ticket);

        $perm = $this->createMock(PermissionService::class);
        $perm->method('canAccessGuestUserAdministration')->willReturn(true);
        $perm->method('getCurrentUserId')->willReturn('agent1');

        $um = $this->createStub(IUserManager::class);
        $um->method('getByEmail')->willReturn([]);

        $req = $this->createStub(IRequest::class);
        return ['ticketMapper' => $tm, 'perm' => $perm, 'userManager' => $um, 'request' => $req];
    }

    public function testInviteCreatesIdentityGrantsProjectSendsMail(): void
    {
        $d = $this->deps();
        $identity = new GuestIdentity();
        $identity->setId(31);
        $identity->setEmail('ext@customer.de');
        $d['identities'] = $this->createMock(GuestIdentityService::class);
        $d['identities']->expects(self::once())
            ->method('findOrCreateWithReuse')
            ->with('ext@customer.de', 'Ext', 'agent1')
            ->willReturn([$identity, false]);
        $d['identities']->expects(self::once())
            ->method('grantProjects')
            ->with(31, [77], null, 'agent1');
        $d['invite'] = $this->createMock(GuestInviteService::class);
        $d['invite']->expects(self::once())->method('sendInvite')->with(31)->willReturn(true);

        $r = $this->controller($d)->inviteCustomer(9);
        $data = $r->getData();
        self::assertTrue($data['success']);
        self::assertSame(31, $data['identity_id']);
        self::assertTrue($data['invite_sent']);
    }

    public function testExistingAccountRejected(): void
    {
        $d = $this->deps();
        $um = $this->createStub(IUserManager::class);
        $um->method('getByEmail')->willReturn([$this->createStub(\OCP\IUser::class)]);
        $d['userManager'] = $um;
        $d['identities'] = $this->createStub(GuestIdentityService::class);
        $d['invite'] = $this->createMock(GuestInviteService::class);
        $d['invite']->expects(self::never())->method('sendInvite');

        $r = $this->controller($d)->inviteCustomer(9);
        self::assertSame(409, $r->getStatus());
    }

    public function testInvalidEmailRejected(): void
    {
        $d = $this->deps('not-an-email');
        $d['identities'] = $this->createStub(GuestIdentityService::class);
        $d['invite'] = $this->createMock(GuestInviteService::class);
        $d['invite']->expects(self::never())->method('sendInvite');
        $r = $this->controller($d)->inviteCustomer(9);
        self::assertSame(422, $r->getStatus());
    }

    public function testPermissionDenied(): void
    {
        $d = $this->deps();
        $perm = $this->createMock(PermissionService::class);
        $perm->method('canAccessGuestUserAdministration')->willReturn(false);
        $d['perm'] = $perm;
        $d['identities'] = $this->createStub(GuestIdentityService::class);
        $d['invite'] = $this->createMock(GuestInviteService::class);
        $d['invite']->expects(self::never())->method('sendInvite');
        $r = $this->controller($d)->inviteCustomer(9);
        self::assertSame(403, $r->getStatus());
    }
}
