<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * CORE V5/V8 TicketCheck → InvoiceCheck deep links (pc_customer_id → receivables).
 */
final class InvoiceCheckDeepLinkContractTest extends TestCase
{
	public function testCustomerShowWiresReceivablesWhenEnabled(): void
	{
		$ctrl = (string) file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/CustomerController.php'
		);
		self::assertStringContainsString("isEnabledForUser('invoicecheck')", $ctrl);
		self::assertStringContainsString("linkToRoute('invoicecheck.page.receivables'", $ctrl);
		self::assertStringContainsString("'customerId'", $ctrl);
		self::assertStringContainsString('invoicingCheckUrl', $ctrl);
	}

	public function testDetailTemplateRendersDeepLink(): void
	{
		$tpl = (string) file_get_contents(
			dirname(__DIR__, 3) . '/templates/customers/detail.php'
		);
		self::assertStringContainsString('invoicingCheckUrl', $tpl);
		self::assertStringContainsString('Open InvoiceCheck', $tpl);
	}

	public function testTicketShowWiresReceivablesWhenCustomerPresent(): void
	{
		$ctrl = (string) file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/TicketController.php'
		);
		self::assertStringContainsString("isEnabledForUser('invoicecheck')", $ctrl);
		self::assertStringContainsString('invoicecheck.page.receivables', $ctrl);
		self::assertStringContainsString('invoicingCheckReceivablesUrl', $ctrl);
		self::assertStringContainsString('customerId', $ctrl);
	}

	public function testTicketDetailTemplateRendersReceivablesDeepLink(): void
	{
		$tpl = (string) file_get_contents(
			dirname(__DIR__, 3) . '/templates/ticket-detail.php'
		);
		self::assertStringContainsString('invoicingCheckReceivablesUrl', $tpl);
		self::assertStringContainsString('Open receivables', $tpl);
	}
}
