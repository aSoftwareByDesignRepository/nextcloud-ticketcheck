<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Repair;

use OCA\Ticketcheck\Repair\UnpinLegacyGuestPins;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * provenance_gate_strands_legacy_writes: pre-flag member pins (written by
 * released <=2.6.0 code on every helpdesk_customers login, no marker) must
 * be healed once at upgrade — non-members lose the pins; current members
 * get the portal_pins provenance flag stamped instead.
 */
class UnpinLegacyGuestPinsTest extends TestCase
{
    private function outputMock(): IOutput
    {
        return $this->createMock(IOutput::class);
    }

    private function memberGroup(array $uids, IGroupManager $gm): void
    {
        $group = $this->createMock(IGroup::class);
        $users = array_map(function (string $uid): IUser {
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn($uid);
            return $u;
        }, $uids);
        $group->method('getUsers')->willReturn($users);
        $gm->method('get')->with('helpdesk_customers')->willReturn($group);
    }

    public function testStrandedNonMemberPinsAreDeleted(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getUsersForUserValue')->willReturnMap([
            ['core', 'defaultapp', 'ticketcheck', ['legacy_a', 'legacy_b']],
            ['firstrunwizard', 'show', '999.0.0', ['legacy_a']],
        ]);
        $config->method('getUserValue')->willReturnMap([
            ['legacy_a', 'core', 'defaultapp', '', 'ticketcheck'],
            ['legacy_a', 'firstrunwizard', 'show', '', '999.0.0'],
            ['legacy_b', 'core', 'defaultapp', '', 'ticketcheck'],
        ]);
        $deletes = new \ArrayObject();
        $config->method('deleteUserValue')
            ->willReturnCallback(static function (string $u, string $app, string $key) use ($deletes): void {
                $deletes->append([$u, $app, $key]);
            });
        $config->expects($this->never())->method('setUserValue');

        $gm = $this->createMock(IGroupManager::class);
        $this->memberGroup([], $gm);

        $step = new UnpinLegacyGuestPins($config, $gm, $this->createMock(LoggerInterface::class));
        $step->run($this->outputMock());

        $this->assertSame([
            ['legacy_a', 'core', 'defaultapp'],
            ['legacy_a', 'firstrunwizard', 'show'],
            ['legacy_b', 'core', 'defaultapp'],
        ], $deletes->getArrayCopy());
    }

    public function testFlaglessMemberGetsProvenanceStampedPinsKept(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getUsersForUserValue')->willReturnMap([
            ['core', 'defaultapp', 'ticketcheck', ['member_a', 'member_b']],
            ['firstrunwizard', 'show', '999.0.0', []],
        ]);
        $config->method('getUserValue')->willReturnMap([
            // member_a has no flag yet; member_b already flagged
            ['member_a', 'ticketcheck', 'portal_pins', '', ''],
            ['member_b', 'ticketcheck', 'portal_pins', '', '1'],
        ]);
        $writes = new \ArrayObject();
        $config->method('setUserValue')
            ->willReturnCallback(static function (string $u, string $app, string $key, string $value) use ($writes): void {
                $writes->append([$u, $app, $key, $value]);
            });
        $config->expects($this->never())->method('deleteUserValue');

        $gm = $this->createMock(IGroupManager::class);
        $this->memberGroup(['member_a', 'member_b'], $gm);

        $step = new UnpinLegacyGuestPins($config, $gm, $this->createMock(LoggerInterface::class));
        $step->run($this->outputMock());

        $this->assertSame([
            ['member_a', 'ticketcheck', 'portal_pins', '1'],
        ], $writes->getArrayCopy());
    }

    public function testForeignValuesAreNeverTouched(): void
    {
        $config = $this->createMock(IConfig::class);
        // no pin-value carriers at all
        $config->method('getUsersForUserValue')->willReturn([]);
        $config->expects($this->never())->method('deleteUserValue');
        $config->expects($this->never())->method('setUserValue');

        $gm = $this->createMock(IGroupManager::class);
        $gm->method('get')->willReturn(null);

        $step = new UnpinLegacyGuestPins($config, $gm, $this->createMock(LoggerInterface::class));
        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info');
        $step->run($output);
    }

    public function testPerUserFailureIsLoggedNotThrown(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getUsersForUserValue')->willReturnMap([
            ['core', 'defaultapp', 'ticketcheck', ['boom']],
            ['firstrunwizard', 'show', '999.0.0', []],
        ]);
        $config->method('getUserValue')
            ->willThrowException(new \RuntimeException('db gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $gm = $this->createMock(IGroupManager::class);
        $this->memberGroup([], $gm);

        $step = new UnpinLegacyGuestPins($config, $gm, $logger);
        $step->run($this->outputMock()); // must not throw — repair steps share the upgrade process
        $this->assertTrue(true);
    }
}
