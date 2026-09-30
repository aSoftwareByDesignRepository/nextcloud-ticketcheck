<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portfolio rule: bulk assign must use directory picker, never free-text UID.
 */
final class BulkAssignPickerContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testBulkActionsJsUsesAssigneePickerNotRawUidModal(): void
	{
		$js = (string) file_get_contents($this->root . '/js/bulk-actions.js');
		$this->assertStringContainsString('openAssigneePickerModal', $js);
		$this->assertStringContainsString('data-tc-bulk-assignee-picker', $js);
		$this->assertStringContainsString('/api/tickets/assignable-users', $js);
		$this->assertStringContainsString('Never type a raw user id', $js);
		$this->assertStringNotContainsString("t('ticketcheck', 'modal_bulk_assign_label'),\n            64", $js);
		$this->assertDoesNotMatchRegularExpression(
			'/handleBulkAssign[\s\S]{0,400}openTextModal/',
			$js,
		);
	}

	public function testBulkAssignableUsersRouteExists(): void
	{
		$routes = (string) file_get_contents($this->root . '/appinfo/routes.php');
		$this->assertStringContainsString('ticket#searchBulkAssignableUsers', $routes);
		$this->assertStringContainsString('/api/tickets/assignable-users', $routes);
	}

	public function testControllerExposesBulkSearchMethod(): void
	{
		$src = (string) file_get_contents($this->root . '/lib/Controller/TicketController.php');
		$this->assertStringContainsString('function searchBulkAssignableUsers(): JSONResponse', $src);
		$this->assertStringContainsString('collectAssignableUsers', $src);
	}

	public function testL10nLabelIsNotUserId(): void
	{
		$en = (string) file_get_contents($this->root . '/l10n/en.json');
		$this->assertStringContainsString('"modal_bulk_assign_label": "Assignee"', $en);
		$this->assertStringNotContainsString('"modal_bulk_assign_label": "User ID"', $en);
	}
}
