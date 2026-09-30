<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Service\GuestPasswordPolicyService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use PHPUnit\Framework\TestCase;

class GuestPasswordPolicyServiceTest extends TestCase
{
	private GuestPasswordPolicyService $policy;

	protected function setUp(): void
	{
		parent::setUp();
		$this->policy = new GuestPasswordPolicyService();
	}

	public function testValidateNewPasswordAcceptsStrongPassword(): void
	{
		$result = $this->policy->validateNewPassword('Str0ng!Passw0rd');
		$this->assertTrue($result['valid']);
		$this->assertNull($result['errorKey']);
	}

	public function testValidateNewPasswordRejectsEmpty(): void
	{
		$result = $this->policy->validateNewPassword('');
		$this->assertFalse($result['valid']);
		$this->assertSame('password_required', $result['errorKey']);
	}

	public function testValidateNewPasswordRejectsShortPassword(): void
	{
		$result = $this->policy->validateNewPassword('Ab1!short');
		$this->assertFalse($result['valid']);
		$this->assertSame('password_minimum_12_characters', $result['errorKey']);
	}

	public function testValidateNewPasswordRejectsMissingUppercase(): void
	{
		$result = $this->policy->validateNewPassword('str0ng!passw0rd');
		$this->assertFalse($result['valid']);
		$this->assertSame('password_requires_uppercase', $result['errorKey']);
	}

	public function testIsSameAsCurrentUsesTimingSafeComparison(): void
	{
		$this->assertTrue($this->policy->isSameAsCurrent('same', 'same'));
		$this->assertFalse($this->policy->isSameAsCurrent('different', 'same'));
		$this->assertFalse($this->policy->isSameAsCurrent('', 'same'));
	}

	public function testEvaluateRequirementsTracksEachRule(): void
	{
		$eval = $this->policy->evaluateRequirements('Aa1!');
		$this->assertFalse($eval['length']);
		$this->assertTrue($eval['upper']);
		$this->assertTrue($eval['lower']);
		$this->assertTrue($eval['number']);
		$this->assertTrue($eval['special']);
	}

	public function testGenerateCompliantPasswordPassesValidation(): void
	{
		for ($i = 0; $i < 20; $i++) {
			$password = $this->policy->generateCompliantPassword(16);
			$result = $this->policy->validateNewPassword($password);
			$this->assertTrue($result['valid'], 'Generated password must satisfy guest policy');
		}
	}

	public function testValidateNewPasswordRejectsServerPolicyFailure(): void
	{
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (ValidatePasswordPolicyEvent $event): void {
				if ($event->getPassword() === 'Str0ng!Passw0rd') {
					throw new HintException('too weak');
				}
			}
		);

		$policy = new GuestPasswordPolicyService($dispatcher);
		$result = $policy->validateNewPassword('Str0ng!Passw0rd');
		$this->assertFalse($result['valid']);
		$this->assertSame('password_policy_rejected', $result['errorKey']);
	}

	public function testGenerateCompliantPasswordRetriesUntilServerPolicyPasses(): void
	{
		$attempt = 0;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (ValidatePasswordPolicyEvent $event) use (&$attempt): void {
				$attempt++;
				if ($attempt < 3) {
					throw new HintException('rejected');
				}
			}
		);

		$policy = new GuestPasswordPolicyService($dispatcher);
		$password = $policy->generateCompliantPassword(16);
		$this->assertGreaterThanOrEqual(3, $attempt);
		$this->assertTrue($policy->validateNewPassword($password)['valid']);
	}
}
