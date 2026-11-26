<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Agent;

use OCA\Ticketcheck\Agent\AgentAccessGate;
use OCA\Ticketcheck\Agent\AgentManifest;
use OCA\Ticketcheck\Agent\AgentSettingsPayload;
use OCA\Ticketcheck\Agent\AgentToken;
use OCA\Ticketcheck\Agent\AgentTokenMapper;
use OCA\Ticketcheck\Agent\AgentTokenService;
use OCA\Ticketcheck\AppInfo\Application;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Visibility contract for the settings section: a user holding live
 * tokens must ALWAYS see it — an admin toggling self-mint off must not
 * orphan a user's ability to inspect/revoke credentials that still work.
 */
final class AgentSettingsPayloadTest extends TestCase
{
	private AgentTokenMapper&MockObject $tokens;
	private AgentAccessGate&MockObject $gate;
	private IConfig&MockObject $config;
	private AgentSettingsPayload $payload;
	private string $manifestFile;

	protected function setUp(): void
	{
		$this->tokens = $this->createMock(AgentTokenMapper::class);
		$this->gate = $this->createMock(AgentAccessGate::class);
		$this->config = $this->createMock(IConfig::class);
		$this->manifestFile = tempnam(sys_get_temp_dir(), 'agent-manifest');
		file_put_contents($this->manifestFile, json_encode([
			'schemaVersion' => 1, 'appId' => 'ticketcheck',
			'agentExposure' => 'declared',
			'tools' => [['name' => 'a', 'kind' => 'read', 'riskClass' => 0, 'method' => 'GET',
				'path' => '/x', 'scope' => 'ticketcheck:things:read']],
		]));
		$manifest = new AgentManifest('ticketcheck', $this->manifestFile);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://nc.test');
		$this->payload = new AgentSettingsPayload(
			$this->tokens, $this->createMock(AgentTokenService::class), $manifest, $this->gate, $this->config, $url,
		);
	}

	protected function tearDown(): void
	{
		@unlink($this->manifestFile);
	}

	private function selfMint(bool $on): void
	{
		$this->config->method('getAppValue')->willReturn($on ? '1' : '0');
	}

	public function testTokenHolderStaysVisibleWhenSelfMintDisabled(): void
	{
		$this->selfMint(false);
		$this->gate->method('canAdminister')->willReturn(false);
		$this->gate->method('canUseApp')->willReturn(true);
		$this->tokens->method('findByUser')->willReturn([new AgentToken()]);
		$p = $this->payload->payload('alice');
		$this->assertTrue($p['visible'], 'a user holding tokens must see the section');
		$this->assertFalse($p['canMint'], 'mint button must stay gated');
	}

	public function testTokenlessUserWithoutMintIsHidden(): void
	{
		$this->selfMint(false);
		$this->gate->method('canAdminister')->willReturn(false);
		$this->gate->method('canUseApp')->willReturn(true);
		$this->tokens->method('findByUser')->willReturn([]);
		$this->assertFalse($this->payload->payload('alice')['visible']);
	}

	public function testSelfMintUserSeesSection(): void
	{
		$this->selfMint(true);
		$this->gate->method('canAdminister')->willReturn(false);
		$this->gate->method('canUseApp')->willReturn(true);
		$this->tokens->method('findByUser')->willReturn([]);
		$this->assertTrue($this->payload->payload('alice')['visible']);
	}

	public function testNonAppUserHiddenEvenWithSelfMintOn(): void
	{
		$this->selfMint(true);
		$this->gate->method('canAdminister')->willReturn(false);
		$this->gate->method('canUseApp')->willReturn(false);
		$this->tokens->method('findByUser')->willReturn([new AgentToken()]);
		$this->assertFalse($this->payload->payload('alice')['visible']);
	}
}
