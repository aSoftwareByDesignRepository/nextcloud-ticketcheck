<?php

declare(strict_types=1);

/**
 * Abstraction over Nextcloud's CSP script nonce source.
 *
 * Core still exposes the nonce manager as a private OC\ class (no public OCP
 * interface yet). This wrapper keeps TicketCheck code on an app-owned contract
 * so unit tests and future NC upgrades only touch one adapter.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Security;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;

class CspNonceProvider
{
	public function __construct(
		private readonly ContentSecurityPolicyNonceManager $nonceManager,
	) {
	}

	public function getNonce(): string
	{
		return $this->nonceManager->getNonce();
	}
}
