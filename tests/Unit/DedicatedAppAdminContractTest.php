<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portfolio §2.1 — App Admin is system admin OR helpdesk_admins OR dedicated list.
 * Dedicated list must grant admin without requiring Nextcloud admin.
 */
final class DedicatedAppAdminContractTest extends TestCase
{
	public function testIsAppAdminUsesOrSemanticsIncludingDedicatedList(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PermissionService.php');
		$start = strpos($src, 'public function isAppAdmin(?string $userId = null): bool');
		$this->assertNotFalse($start);
		$end = strpos($src, 'public function isAdmin(): bool', $start);
		$this->assertNotFalse($end);
		$body = substr($src, $start, $end - $start);
		$this->assertStringContainsString('isNcAdminCached($userId)', $body);
		$this->assertStringContainsString('GROUP_HELPDESK_ADMINS', $body);
		$this->assertStringContainsString('getAppAdminUserIds()', $body);
		$this->assertStringContainsString('return in_array($userId, $this->getAppAdminUserIds(), true);', $body);
		$this->assertStringNotContainsString('if (!$this->isNcAdminCached($userId))', $body);
	}
}
