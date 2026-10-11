<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCP\IConfig;

/**
 * HMAC reply-token for inbound ticket addressing.
 *
 * Outbound Reply-To uses  local+{ticketId}-{token}@domain  where the token
 * binds the ticket id to the ticket's authorized customer email. Without the
 * binding, an agent who sees a notification's +{id}-token could forge a
 * customer From and comment on tickets they do not own (ARGUS-04).
 *
 * Verification uses hash_equals — never ==.
 */
final class ReplyTokenService
{
	private const KEY = 'tc-reply';
	private const TOKEN_LEN = 10;

	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function token(int $ticketId, string $customerEmail): string
	{
		$secret = (string)$this->config->getSystemValue('secret', '');
		$payload = self::KEY . '|' . $ticketId . '|' . strtolower(trim($customerEmail));
		return substr(hash_hmac('sha256', $payload, $secret), 0, self::TOKEN_LEN);
	}

	public function verify(int $ticketId, string $token, string $customerEmail): bool
	{
		$token = strtolower(trim($token));
		if ($token === '' || preg_match('/^[a-f0-9]{10}$/', $token) !== 1) {
			return false;
		}
		return hash_equals($this->token($ticketId, $customerEmail), $token);
	}

	/**
	 * Build the tokenized reply address for outbound mail.
	 * Returns '' when the configured inbound address is unusable.
	 */
	public function replyAddress(string $inboundAddress, int $ticketId, string $customerEmail): string
	{
		$inboundAddress = strtolower(trim($inboundAddress));
		$atPos = strpos($inboundAddress, '@');
		if ($atPos === false || $atPos === 0) {
			return '';
		}
		$local = substr($inboundAddress, 0, $atPos);
		$domain = substr($inboundAddress, $atPos + 1);
		if ($local === '' || $domain === '') {
			return '';
		}
		return $local . '+' . $ticketId . '-' . $this->token($ticketId, $customerEmail) . '@' . $domain;
	}
}
