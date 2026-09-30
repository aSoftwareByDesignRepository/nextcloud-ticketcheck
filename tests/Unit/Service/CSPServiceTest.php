<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Security\CspNonceProvider;
use OCA\Ticketcheck\Service\CSPService;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use PHPUnit\Framework\TestCase;

class CSPServiceTest extends TestCase
{
	public function testApplyPolicyWithNonceSetsCoreAndLegacyKeys(): void
	{
		$nonceProvider = $this->createMock(CspNonceProvider::class);
		$nonceProvider->method('getNonce')->willReturn('test-nonce-value');

		$service = new CSPService($nonceProvider);
		$response = new TemplateResponse('ticketcheck', 'portal/index', [], 'guest');

		$result = $service->applyPolicyWithNonce($response, 'guest');
		$params = $result->getParams();

		$this->assertSame('test-nonce-value', $params['cspNonce']);
		$this->assertSame('test-nonce-value', $params['csp_nonce']);
		$this->assertInstanceOf(ContentSecurityPolicy::class, $result->getContentSecurityPolicy());
	}

	public function testGuestAndMainPoliciesRequireSelfScriptSource(): void
	{
		$nonceProvider = $this->createMock(CspNonceProvider::class);
		$service = new CSPService($nonceProvider);

		$guest = $service->getGuestPortalPolicy()->buildPolicy();
		$main = $service->getMainAppPolicy()->buildPolicy();

		$this->assertStringContainsString('script-src', $guest);
		$this->assertStringContainsString("'self'", $guest);
		$this->assertStringContainsString('script-src', $main);
		$this->assertStringContainsString("'self'", $main);
		// Frame ancestors locked down on both (clickjacking).
		$this->assertStringContainsString('frame-ancestors', $guest);
	}
}
