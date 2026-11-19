<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Comment permission matrix (spec comment-management-2026-11 MH-A03/04,
 * US-A03 AC-2, AS-04, NN-B01):
 *
 *  - staff (agent/admin) may edit/delete/toggle either direction on any
 *    reachable comment, exempt from the author edit window — but never on
 *    tombstones;
 *  - a non-staff author may edit/delete only inside the exclusive window
 *    and may only toggle public→internal, never the reverse;
 *  - move requires edit rights on BOTH tickets;
 *  - guests are denied everywhere.
 */
class PermissionServiceCommentMatrixTest extends TestCase
{
    private function comment(array $fields = []): Comment
    {
        $c = new Comment();
        $c->setTicketId($fields['ticket_id'] ?? 10);
        $c->setUserId($fields['user_id'] ?? 'author1');
        $c->setAuthorName('Author One');
        $c->setAuthorEmail('author@example.invalid');
        $c->setContent('body');
        $c->setIsInternal($fields['is_internal'] ?? false);
        $c->setCreatedAt($fields['created_at'] ?? new \DateTime());
        if (array_key_exists('deleted_at', $fields)) {
            $c->setDeletedAt($fields['deleted_at']);
        }
        return $c;
    }

    private function ticket(): Ticket
    {
        $t = new Ticket();
        return $t;
    }

    /**
     * @param list<string> $groups
     * @param array<string, string> $config
     */
    private function service(?string $uid, array $groups, array $config = []): PermissionService
    {
        $user = null;
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
        }
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $u, string $gid) use ($groups): bool {
                return in_array($gid, $groups, true);
            }
        );

        $cfg = $this->createMock(IConfig::class);
        $cfg->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = '') use ($config): string {
                return $config[$key] ?? $default;
            }
        );

        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(0);
        $func = $this->createMock(IFunctionBuilder::class);
        $func->method('count')->willReturn($this->createMock(IQueryFunction::class));
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq');
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('func')->willReturn($func);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeQuery')->willReturn($result);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        return new PermissionService(
            $session,
            $groupManager,
            $cfg,
            $this->createMock(GuestProjectAccessMapper::class),
            $db,
        );
    }

    public function testStaffMayEditDeleteAndToggleEitherDirection(): void
    {
        $svc = $this->service('agent1', ['helpdesk_agents']);
        $comment = $this->comment(['created_at' => (new \DateTime())->modify('-2 years')]);
        $ticket = $this->ticket();

        $this->assertTrue($svc->canEditComment($comment, $ticket));
        $this->assertTrue($svc->canDeleteComment($comment, $ticket));
        $this->assertTrue($svc->canToggleCommentVisibility($comment, $ticket, true));
        $this->assertTrue($svc->canToggleCommentVisibility($comment, $ticket, false));
    }

    public function testStaffCannotMutateTombstone(): void
    {
        $svc = $this->service('agent1', ['helpdesk_agents']);
        $comment = $this->comment(['deleted_at' => new \DateTime()]);
        $ticket = $this->ticket();

        $this->assertFalse($svc->canEditComment($comment, $ticket));
        $this->assertFalse($svc->canDeleteComment($comment, $ticket));
        $this->assertFalse($svc->canToggleCommentVisibility($comment, $ticket, true));
    }

    public function testAuthorWithinWindowMayEditAndDelete(): void
    {
        $svc = $this->service('author1', []);
        $comment = $this->comment(['created_at' => (new \DateTime())->modify('-5 minutes')]);
        $ticket = $this->ticket();

        $this->assertTrue($svc->canEditComment($comment, $ticket));
        $this->assertTrue($svc->canDeleteComment($comment, $ticket));
    }

    public function testAuthorOutsideWindowDenied(): void
    {
        $svc = $this->service('author1', []);
        $comment = $this->comment(['created_at' => (new \DateTime())->modify('-2 days')]);
        $ticket = $this->ticket();

        $this->assertFalse($svc->canEditComment($comment, $ticket));
        $this->assertFalse($svc->canDeleteComment($comment, $ticket));
    }

    public function testNonAuthorWithinWindowDenied(): void
    {
        $svc = $this->service('someone-else', []);
        $comment = $this->comment(['created_at' => new \DateTime()]);
        $ticket = $this->ticket();

        $this->assertFalse($svc->canEditComment($comment, $ticket));
    }

    public function testAuthorMayOnlyMakeInternalNeverPublic(): void
    {
        $svc = $this->service('author1', []);
        $comment = $this->comment(['created_at' => new \DateTime()]);
        $ticket = $this->ticket();

        $this->assertTrue(
            $svc->canToggleCommentVisibility($comment, $ticket, true),
            'author may pull own comment to internal'
        );
        $this->assertFalse(
            $svc->canToggleCommentVisibility($comment, $ticket, false),
            'author may never publish an internal note (US-A03 AC-2)'
        );
    }

    public function testZeroWindowDisablesAuthorSelfEdit(): void
    {
        $svc = $this->service('author1', [], ['comment_edit_window_minutes' => '0']);
        $comment = $this->comment(['created_at' => new \DateTime()]);
        $ticket = $this->ticket();

        $this->assertFalse($svc->canEditComment($comment, $ticket));
    }

    public function testWindowConfigParsing(): void
    {
        $this->assertSame(1440, $this->service('a', [])->commentEditWindowMinutes());
        $this->assertSame(30, $this->service('a', [], ['comment_edit_window_minutes' => '30'])->commentEditWindowMinutes());
        $this->assertSame(1440, $this->service('a', [], ['comment_edit_window_minutes' => 'bogus'])->commentEditWindowMinutes());
        $this->assertSame(0, $this->service('a', [], ['comment_edit_window_minutes' => '0'])->commentEditWindowMinutes());
    }

    public function testGuestDeniedEverywhere(): void
    {
        $svc = $this->service('guest1', ['helpdesk_customers']);
        $comment = $this->comment(['user_id' => 'guest1']);
        $ticket = $this->ticket();
        $other = $this->ticket();

        $this->assertFalse($svc->canEditComment($comment, $ticket));
        $this->assertFalse($svc->canDeleteComment($comment, $ticket));
        $this->assertFalse($svc->canToggleCommentVisibility($comment, $ticket, true));
        $this->assertFalse($svc->canMoveCommentsBetween($ticket, $other));
    }

    public function testMoveRequiresStaffOnBothSides(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket();

        $this->assertTrue(
            $this->service('agent1', ['helpdesk_agents'])->canMoveCommentsBetween($ticket, $other)
        );
        $this->assertFalse(
            $this->service('author1', [])->canMoveCommentsBetween($ticket, $other),
            'non-staff authors can never move items, window or not'
        );
        $this->assertFalse(
            $this->service(null, [])->canMoveCommentsBetween($ticket, $other)
        );
    }
}
