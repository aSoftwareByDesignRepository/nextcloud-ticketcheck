<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Family rule: assignment surfaces never offer deactivated Nextcloud
 * accounts. For TicketCheck that means escalation targets and the
 * access-policy impact preview.
 */
final class DisabledAccountGuardContractTest extends TestCase
{
	private function src(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/SettingsController.php');
	}

	public function testAssignableUsersSkipsDeactivatedGroupMembers(): void
	{
		$src = $this->src();
		$this->assertMatchesRegularExpression(
			'/function getAssignableUsers[\s\S]{0,2500}if \(!\$user->isEnabled\(\)\)[\s\S]{0,2500}if \(!\$user->isEnabled\(\)\)/',
			$src,
			'getAssignableUsers must skip deactivated accounts in the group loop AND the directory fallback'
		);
	}

	public function testPolicyImpactPreviewSkipsDeactivatedAccounts(): void
	{
		$src = $this->src();
		$this->assertMatchesRegularExpression(
			'/function calculatePolicyImpact[\s\S]{0,2000}if \(!\$user->isEnabled\(\)\)/',
			$src,
			'calculatePolicyImpact must not count deactivated accounts in the blast radius'
		);
	}
}
