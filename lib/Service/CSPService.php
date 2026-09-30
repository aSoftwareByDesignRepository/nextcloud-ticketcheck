<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Security\CspNonceProvider;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;

/**
 * Centralized CSP policy management for TicketCheck.
 *
 * Nonces come from {@see CspNonceProvider} so `core/templates/layout.guest.php`
 * (`cspNonce`) and app inline scripts (`csp_nonce`) stay aligned.
 */
class CSPService
{
	public function __construct(
		private readonly CspNonceProvider $nonceProvider,
	) {
	}

	/**
	 * Base policy shared by all contexts
	 */
	public function getDefaultPolicy(): ContentSecurityPolicy
	{
		$policy = new ContentSecurityPolicy();

		// self for scripts and styles; no unsafe-inline/eval
		$policy->addAllowedScriptDomain("'self'");
		$policy->addAllowedStyleDomain("'self'");

		// images and fonts: allow data URIs; media: allow blob
		$policy->addAllowedImageDomain("'self'");
		$policy->addAllowedImageDomain('data:');
		$policy->addAllowedImageDomain('blob:');
		$policy->addAllowedFontDomain("'self'");
		$policy->addAllowedFontDomain('data:');
		$policy->addAllowedMediaDomain("'self'");
		$policy->addAllowedMediaDomain('blob:');

		// AJAX/fetch
		$policy->addAllowedConnectDomain("'self'");

		// clickjacking protection
		$policy->addAllowedFrameAncestorDomain('none');

		return $policy;
	}

	/**
	 * Strict policy for guest portal pages (app-owned guest layout).
	 *
	 * Note: current Nextcloud `ContentSecurityPolicy` still emits
	 * `style-src … 'unsafe-inline'` by default for theming compatibility.
	 * TicketCheck guest templates still avoid app-owned inline styles so we
	 * stay ready when core tightens style-src.
	 */
	public function getGuestPortalPolicy(): ContentSecurityPolicy
	{
		return $this->getDefaultPolicy();
	}

	/**
	 * Policy for main app pages (agents/admins).
	 *
	 * Explicitly allows style unsafe-inline for Nextcloud user chrome / theming
	 * (same residual as core’s default CSP builder on current NC versions).
	 */
	public function getMainAppPolicy(): ContentSecurityPolicy
	{
		$policy = $this->getDefaultPolicy();
		$policy->allowInlineStyle(true);

		return $policy;
	}

	/**
	 * Policy for modal dialogs if specific relaxations are ever needed
	 */
	public function getModalPolicy(): ContentSecurityPolicy
	{
		return $this->getMainAppPolicy();
	}

	/**
	 * Apply CSP and nonce to a TemplateResponse, returning the same response.
	 */
	public function applyPolicyWithNonce(TemplateResponse $response, string $context): TemplateResponse
	{
		switch ($context) {
			case 'guest':
				$policy = $this->getGuestPortalPolicy();
				break;
			case 'modal':
				$policy = $this->getModalPolicy();
				break;
			case 'main':
			case 'settings':
			default:
				$policy = $this->getMainAppPolicy();
				break;
		}

		$nonce = $this->nonceProvider->getNonce();
		$params = $response->getParams();
		// NC core guest/user layouts read `cspNonce`; legacy app templates use `csp_nonce`.
		$params['cspNonce'] = $nonce;
		$params['csp_nonce'] = $nonce;
		$response->setParams($params);
		$response->setContentSecurityPolicy($policy);

		return $response;
	}
}
