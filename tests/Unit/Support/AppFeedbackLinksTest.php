<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Support;

use OCA\Ticketcheck\Support\AppFeedbackLinks;
use PHPUnit\Framework\TestCase;

final class AppFeedbackLinksTest extends TestCase
{
	public function testProblemMailtoUsesDevInboxAndEncodedSubject(): void
	{
		$links = new AppFeedbackLinks('ticketcheck', 'TicketCheck', '1.2.3');
		$en = $links->problemMailto(['pageUrl' => '/apps/ticketcheck/today'], 'en');
		$de = $links->problemMailto(['pageUrl' => '/apps/ticketcheck/today'], 'de');
		self::assertStringStartsWith('mailto:dev@software-by-design.de?subject=', $en);
		self::assertStringContainsString(rawurlencode('TicketCheck: Problem report'), $en);
		self::assertStringContainsString(rawurlencode('TicketCheck: Fehlermeldung'), $de);
		self::assertStringContainsString('body=', $en);
		self::assertStringNotContainsString("\n", $en);
		self::assertStringNotContainsString('info@software-by-design.de', $en);
	}

	public function testIdeaMailtoUsesFeedbackSubject(): void
	{
		$links = new AppFeedbackLinks('ticketcheck', 'TicketCheck');
		$href = $links->ideaMailto([], 'de');
		self::assertStringContainsString(rawurlencode('TicketCheck: Feedback'), $href);
	}

	public function testGithubIssuesUrlIsPublicVendorRepo(): void
	{
		$links = new AppFeedbackLinks('ticketcheck', 'TicketCheck');
		self::assertSame(
			'https://github.com/aSoftwareByDesignRepository/nextcloud-ticketcheck/issues',
			$links->githubIssuesUrl()
		);
	}

	public function testSanitizePageUrlStripsCredentialQueryKeys(): void
	{
		$links = new AppFeedbackLinks('ticketcheck', 'TicketCheck');
		$clean = $links->sanitizePageUrl('https://cloud.example/apps/ticketcheck/x?token=abc&keep=1&password=no');
		self::assertStringContainsString('keep=1', $clean);
		self::assertStringNotContainsString('token=', $clean);
		self::assertStringNotContainsString('password=', $clean);
		self::assertSame('', $links->sanitizePageUrl('javascript:alert(1)'));
		self::assertSame('', $links->sanitizePageUrl('data:text/html,<script>alert(1)</script>'));
		self::assertSame('', $links->sanitizePageUrl('vbscript:msgbox(1)'));
		self::assertSame('', $links->sanitizePageUrl("https://x.example/\r\nBcc:evil@example.com"));
		$keep = $links->sanitizePageUrl('https://cloud.example/apps/ticketcheck/list?keep=1&token=abc');
		self::assertStringContainsString('keep=1', $keep);
		self::assertStringNotContainsString('token=', $keep);
	}

	public function testSanitizePageUrlStripsNextcloudUpgradeAckQueryKey(): void
	{
		$links = new AppFeedbackLinks('ticketcheck', 'TicketCheck');
		$noisy = '/apps/ticketcheck/?IKnowThatThisIsABigInstanceAndTheUpdateRequestCouldRunIntoATimeoutAndHowToRestoreABackup=IAmSuperSureToDoThis';
		self::assertSame('/apps/ticketcheck/', $links->sanitizePageUrl($noisy));
	}

	public function testMailtoBodyOmitsIdentityAndRejectsUnsafeErrorCodes(): void
	{
		$links = new AppFeedbackLinks('ticketcheck', 'TicketCheck', '9.9.9');
		$href = $links->problemMailto([
			'errorCode' => 'CONFLICT',
			'pageUrl' => '/apps/ticketcheck/',
		], 'en');
		$body = urldecode((string)(parse_url($href, PHP_URL_QUERY) ? explode('body=', (string)parse_url($href, PHP_URL_QUERY), 2)[1] ?? '' : ''));
		self::assertStringContainsString('Error code: CONFLICT', $body);
		self::assertStringContainsString('App id: ticketcheck', $body);
		self::assertStringNotContainsString('uid=', $body);
		self::assertStringNotContainsString('@', explode('--- Auto-filled', $body)[1] ?? $body);

		$bad = $links->problemMailto(['errorCode' => 'x <script>'], 'en');
		$badBody = urldecode((string)(parse_url($bad, PHP_URL_QUERY) ? explode('body=', (string)parse_url($bad, PHP_URL_QUERY), 2)[1] ?? '' : ''));
		self::assertStringNotContainsString('Error code:', $badBody);
	}

	public function testRejectsUnsafeDisplayName(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new AppFeedbackLinks('ticketcheck', "Evil\r\nBcc: attacker@example.com");
	}

	public function testForLocalePayload(): void
	{
		$links = new AppFeedbackLinks('ticketcheck', 'TicketCheck', '1.0.0');
		$payload = $links->forLocale('de', ['pageUrl' => '/apps/ticketcheck/']);
		self::assertSame('dev@software-by-design.de', $payload['feedbackEmail']);
		self::assertStringStartsWith('mailto:dev@software-by-design.de', $payload['problemMailto']);
		self::assertStringStartsWith('mailto:dev@software-by-design.de', $payload['ideaMailto']);
		self::assertStringStartsWith('https://github.com/aSoftwareByDesignRepository/', $payload['githubIssuesUrl']);
		self::assertSame('ticketcheck', $payload['appId']);
	}
}
