<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use OCP\Security\PasswordContext;

/**
 * Guest portal password policy (portal change-password and related flows).
 */
final class GuestPasswordPolicyService
{
	public const MIN_LENGTH = 12;

	private const GENERATION_MAX_ATTEMPTS = 32;

	public function __construct(
		private ?IEventDispatcher $eventDispatcher = null,
	) {
	}

	/**
	 * @return array{valid: bool, errorKey: string|null}
	 */
	public function validateNewPassword(string $password): array
	{
		if ($password === '') {
			return ['valid' => false, 'errorKey' => 'password_required'];
		}
		if (strlen($password) < self::MIN_LENGTH) {
			return ['valid' => false, 'errorKey' => 'password_minimum_12_characters'];
		}
		if (!preg_match('/[A-Z]/', $password)) {
			return ['valid' => false, 'errorKey' => 'password_requires_uppercase'];
		}
		if (!preg_match('/[a-z]/', $password)) {
			return ['valid' => false, 'errorKey' => 'password_requires_lowercase'];
		}
		if (!preg_match('/[0-9]/', $password)) {
			return ['valid' => false, 'errorKey' => 'password_requires_number'];
		}
		if (!preg_match('/[^A-Za-z0-9]/', $password)) {
			return ['valid' => false, 'errorKey' => 'password_requires_special_character'];
		}

		if (!$this->passesServerPasswordPolicy($password)) {
			return ['valid' => false, 'errorKey' => 'password_policy_rejected'];
		}

		return ['valid' => true, 'errorKey' => null];
	}

	public function isSameAsCurrent(string $newPassword, string $currentPassword): bool
	{
		return $newPassword !== '' && hash_equals($currentPassword, $newPassword);
	}

	/**
	 * @return array<string, bool> keyed by requirement id (length, upper, lower, number, special)
	 */
	public function evaluateRequirements(string $password): array
	{
		return [
			'length' => strlen($password) >= self::MIN_LENGTH,
			'upper' => (bool) preg_match('/[A-Z]/', $password),
			'lower' => (bool) preg_match('/[a-z]/', $password),
			'number' => (bool) preg_match('/[0-9]/', $password),
			'special' => (bool) preg_match('/[^A-Za-z0-9]/', $password),
		];
	}

	/**
	 * Random password that satisfies guest policy and Nextcloud password_policy (when installed).
	 *
	 * @throws \RuntimeException when no compliant password could be generated
	 */
	public function generateCompliantPassword(int $length = 16): string
	{
		$length = max($length, self::MIN_LENGTH);

		for ($attempt = 0; $attempt < self::GENERATION_MAX_ATTEMPTS; $attempt++) {
			$password = $this->buildRandomPassword($length);
			$result = $this->validateNewPassword($password);
			if ($result['valid']) {
				return $password;
			}
		}

		throw new \RuntimeException('Unable to generate a password that satisfies all policies');
	}

	/**
	 * Whether the password passes the server-wide password_policy app (if present).
	 */
	public function passesServerPasswordPolicy(string $password): bool
	{
		if ($this->eventDispatcher === null) {
			return true;
		}

		try {
			$this->eventDispatcher->dispatchTyped(
				new ValidatePasswordPolicyEvent($password, PasswordContext::ACCOUNT),
			);

			return true;
		} catch (HintException) {
			return false;
		}
	}

	private function buildRandomPassword(int $length): string
	{
		$pools = [
			'abcdefghijklmnopqrstuvwxyz',
			'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
			'0123456789',
			'!@#$%^&*',
		];
		$chars = [];
		foreach ($pools as $pool) {
			$chars[] = $pool[random_int(0, strlen($pool) - 1)];
		}
		$all = implode('', $pools);
		for ($i = count($chars); $i < $length; $i++) {
			$chars[] = $all[random_int(0, strlen($all) - 1)];
		}
		shuffle($chars);

		return implode('', $chars);
	}
}
