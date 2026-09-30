<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\CSPService;
use OCP\AppFramework\Http\TemplateResponse;

/**
 * CSP Configuration for Helpdesk
 */
trait CSPTrait
{
	private ?CSPService $cspService = null;

	protected function setCspService(CSPService $cspService): void
	{
		$this->cspService = $cspService;
	}

	/**
	 * Configure CSP using the injected service and inject nonce.
	 * Unit tests that omit setCspService() leave CSP as a no-op.
	 */
	protected function configureCSPWithNonce(TemplateResponse $response, string $context = 'main'): TemplateResponse
	{
		if ($this->cspService === null) {
			return $response;
		}

		return $this->cspService->applyPolicyWithNonce($response, $context);
	}
}
