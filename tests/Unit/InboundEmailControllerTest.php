<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\InboundEmailController;
use OCA\Ticketcheck\Service\InboundEmailService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InboundEmailControllerTest extends TestCase
{
    private IRequest $request;
    private InboundEmailService $inboundEmailService;
    private IConfig $config;
    private IFactory $l10nFactory;
    private ICacheFactory $cacheFactory;
    private ICache $cache;

    private InboundEmailController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->inboundEmailService = $this->createMock(InboundEmailService::class);
        $this->config = $this->createMock(IConfig::class);
        $this->l10nFactory = $this->createMock(IFactory::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cache = $this->createMock(ICache::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
        $this->l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);
        $this->cacheFactory->method('createDistributed')->with('ticketcheck-inbound-webhook')->willReturn($this->cache);
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->controller = new InboundEmailController(
            'ticketcheck',
            $this->request,
            $this->inboundEmailService,
            $this->config,
            $this->l10nFactory,
            $this->cacheFactory,
            $this->createMock(ILockingProvider::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: string, 2: string} timestamp, signature, raw JSON body
     */
    private function prepareGenericSignedJson(array $payload, string $secret = 'secret'): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $ref = new \ReflectionProperty(InboundEmailController::class, 'rawBody');
        $ref->setAccessible(true);
        $ref->setValue($this->controller, $body);

        return [$timestamp, $signature, $body];
    }

    public function testWebhookReturnsServiceUnavailableWhenInboundRoutingDisabled(): void
    {
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/x-www-form-urlencoded'],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
            ['X-Webhook-Timestamp', ''],
        ]);
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::never())->method('set');
        $this->request->method('getParams')->willReturn([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_enabled' => 'no',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->inboundEmailService->expects(self::never())->method('processWebhook');

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(503, $response->getStatus());
        self::assertSame(['error' => 'webhook_not_configured'], $response->getData());
    }

    public function testWebhookRejectsRecipientMismatch(): void
    {
        [$timestamp, $signature] = $this->prepareGenericSignedJson([
            'to' => 'other+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ]);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/json'],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
            ['X-Webhook-Timestamp', $timestamp],
            ['X-Webhook-Signature', $signature],
        ]);
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('set');
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_signing_secret' => 'secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->inboundEmailService->expects(self::never())->method('processWebhook');

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(400, $response->getStatus());
        self::assertSame(['error' => 'invalid_payload'], $response->getData());
    }

    public function testWebhookReturns503WhenGenericSigningSecretMissing(): void
    {
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/x-www-form-urlencoded'],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
            ['X-Webhook-Timestamp', ''],
        ]);
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::never())->method('set');
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    'inbound_email_webhook_provider' => 'generic',
                    'inbound_email_webhook_signing_secret' => '',
                    default => $default,
                };
            }
        );
        $this->inboundEmailService->expects(self::never())->method('processWebhook');

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(503, $response->getStatus());
        self::assertSame(['error' => 'webhook_not_configured'], $response->getData());
    }

    public function testWebhookAcceptsPlusAddressForConfiguredInboundAddress(): void
    {
        [$timestamp, $signature] = $this->prepareGenericSignedJson([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ]);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/json'],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
            ['X-Webhook-Timestamp', $timestamp],
            ['X-Webhook-Signature', $signature],
        ]);
        $this->cache->method('get')->willReturnCallback(
            static function (string $key): mixed {
                if (str_starts_with($key, 'rl:')) {
                    return null;
                }
                if (str_starts_with($key, 'replay:')) {
                    return null;
                }
                return null;
            }
        );
        $this->cache->expects(self::exactly(2))->method('set');
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_signing_secret' => 'secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->inboundEmailService->expects(self::once())
            ->method('processWebhook')
            ->willReturn(['success' => true, 'ticket_id' => 123]);

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(200, $response->getStatus());
        self::assertSame(['success' => true, 'ticket_id' => 123], $response->getData());
    }

    public function testWebhookReturnsTooManyRequestsWhenRateLimitExceeded(): void
    {
        [$timestamp, $signature] = $this->prepareGenericSignedJson([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ]);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/json'],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
            ['X-Webhook-Timestamp', $timestamp],
            ['X-Webhook-Signature', $signature],
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_signing_secret' => 'secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->cache->method('get')->willReturnMap([
            ['rl:' . hash('sha256', '127.0.0.1|abc'), 60],
        ]);
        $this->cache->expects(self::never())->method('set');
        $this->inboundEmailService->expects(self::never())->method('processWebhook');

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(429, $response->getStatus());
        self::assertSame(['error' => 'too_many_requests'], $response->getData());
    }

    public function testWebhookRejectsReplayPayload(): void
    {
        [$timestamp, $signature] = $this->prepareGenericSignedJson([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ]);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/json'],
            ['X-Webhook-Id', 'id-1'],
            ['Message-Id', null],
            ['X-Webhook-Timestamp', $timestamp],
            ['X-Webhook-Signature', $signature],
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_signing_secret' => 'secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->cache->method('get')->willReturnCallback(
            static function (string $key): mixed {
                if (str_starts_with($key, 'rl:')) {
                    return null;
                }
                if (str_starts_with($key, 'replay:')) {
                    return 1;
                }
                return null;
            }
        );
        $this->cache->expects(self::once())->method('set');
        $this->inboundEmailService->expects(self::never())->method('processWebhook');

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(400, $response->getStatus());
        self::assertSame(['error' => 'invalid_payload'], $response->getData());
    }

    public function testReplayFingerprintIncludesHtmlBodyForHtmlOnlyMessages(): void
    {
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
            ['X-Webhook-Timestamp', ''],
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_provider' => 'generic',
                    default => $default,
                };
            }
        );

        $method = new \ReflectionMethod(InboundEmailController::class, 'buildReplayCacheKey');
        $method->setAccessible(true);

        $basePayload = [
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
        ];
        $firstKey = $method->invoke($this->controller, $basePayload + ['html' => '<p>first</p>']);
        $secondKey = $method->invoke($this->controller, $basePayload + ['html' => '<p>second</p>']);

        self::assertNotSame($firstKey, $secondKey);
    }

    public function testMailgunReplayKeyIgnoresBodySoSignaturePackageCannotBeSwapped(): void
    {
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_provider' => 'mailgun',
                    default => $default,
                };
            }
        );

        $method = new \ReflectionMethod(InboundEmailController::class, 'buildReplayCacheKey');
        $method->setAccessible(true);

        $signature = [
            'timestamp' => '1710000000',
            'token' => 'tok-abc',
            'signature' => 'sig',
        ];
        $firstKey = $method->invoke($this->controller, [
            'signature' => $signature,
            'from' => 'a@example.com',
            'text' => 'legit',
        ]);
        $secondKey = $method->invoke($this->controller, [
            'signature' => $signature,
            'from' => 'attacker@evil.test',
            'text' => 'swapped body',
        ]);

        self::assertSame($firstKey, $secondKey);
    }

    public function testWebhookRejectsInvalidHmacSignatureWhenSigningSecretConfigured(): void
    {
        $body = json_encode([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $ref = new \ReflectionProperty(InboundEmailController::class, 'rawBody');
        $ref->setAccessible(true);
        $ref->setValue($this->controller, $body);

        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/json'],
            ['X-Webhook-Timestamp', $timestamp],
            ['X-Webhook-Signature', 'invalid'],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_signing_secret' => 'secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->cache->expects(self::never())->method('set');
        $this->inboundEmailService->expects(self::never())->method('processWebhook');

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(401, $response->getStatus());
        self::assertSame(['error' => 'invalid_token'], $response->getData());
    }

    public function testWebhookRejectsGenericEmptyBodyEvenWithValidEmptyHmac(): void
    {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.', 'secret');
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/x-www-form-urlencoded'],
            ['X-Webhook-Timestamp', $timestamp],
            ['X-Webhook-Signature', $signature],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
        ]);
        $this->request->method('getParams')->willReturn([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_signing_secret' => 'secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->cache->expects(self::never())->method('set');
        $this->inboundEmailService->expects(self::never())->method('processWebhook');

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(400, $response->getStatus());
        self::assertSame(['error' => 'invalid_payload'], $response->getData());
    }

    public function testWebhookAcceptsValidHmacSignatureWhenSigningSecretConfigured(): void
    {
        [$timestamp, $signature] = $this->prepareGenericSignedJson([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ]);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/json'],
            ['X-Webhook-Timestamp', $timestamp],
            ['X-Webhook-Signature', $signature],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_signing_secret' => 'secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->cache->method('get')->willReturnCallback(
            static function (string $key): mixed {
                if (str_starts_with($key, 'rl:')) {
                    return null;
                }
                if (str_starts_with($key, 'replay:')) {
                    return null;
                }
                return null;
            }
        );
        $this->cache->expects(self::exactly(2))->method('set');
        $this->inboundEmailService->expects(self::once())
            ->method('processWebhook')
            ->willReturn(['success' => true, 'ticket_id' => 123]);

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(200, $response->getStatus());
        self::assertSame(['success' => true, 'ticket_id' => 123], $response->getData());
    }

    public function testWebhookRejectsInvalidMailgunSignatureForMailgunProvider(): void
    {
        $timestamp = (string) time();
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/x-www-form-urlencoded'],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
        ]);
        $this->request->method('getParams')->willReturn([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
            'timestamp' => $timestamp,
            'token' => 'tok',
            'signature' => 'invalid',
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_provider' => 'mailgun',
                    'inbound_email_webhook_signing_secret' => 'mailgun-secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::never())->method('set');
        $this->inboundEmailService->expects(self::never())->method('processWebhook');

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(401, $response->getStatus());
        self::assertSame(['error' => 'invalid_token'], $response->getData());
    }

    public function testWebhookAcceptsValidMailgunSignatureForMailgunProvider(): void
    {
        $timestamp = (string) time();
        $token = 'tok';
        $signature = hash_hmac('sha256', $timestamp . $token, 'mailgun-secret');
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/x-www-form-urlencoded'],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
            ['X-Webhook-Timestamp', ''],
        ]);
        $this->request->method('getParams')->willReturn([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => $signature,
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_provider' => 'mailgun',
                    'inbound_email_webhook_signing_secret' => 'mailgun-secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->cache->method('get')->willReturnCallback(
            static function (string $key): mixed {
                if (str_starts_with($key, 'rl:')) {
                    return null;
                }
                if (str_starts_with($key, 'replay:')) {
                    return null;
                }
                return null;
            }
        );
        $this->cache->expects(self::exactly(2))->method('set');
        $this->cache->expects(self::never())->method('remove');
        $this->inboundEmailService->expects(self::once())
            ->method('processWebhook')
            ->willReturn(['success' => true, 'ticket_id' => 123]);

        $response = $this->controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(200, $response->getStatus());
        self::assertSame(['success' => true, 'ticket_id' => 123], $response->getData());
    }

    public function testWebhookClaimsReplayFingerprintBeforeProcessWebhook(): void
    {
        [$timestamp, $signature] = $this->prepareGenericSignedJson([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ]);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/json'],
            ['X-Webhook-Timestamp', $timestamp],
            ['X-Webhook-Signature', $signature],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_signing_secret' => 'secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );

        $order = [];
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set')->willReturnCallback(static function (string $key) use (&$order): void {
            if (str_starts_with($key, 'replay:')) {
                $order[] = 'claim';
            }
            if (str_starts_with($key, 'rl:')) {
                $order[] = 'rate';
            }
        });
        $this->inboundEmailService->expects(self::once())
            ->method('processWebhook')
            ->willReturnCallback(static function () use (&$order): array {
                $order[] = 'process';
                return ['success' => true, 'ticket_id' => 55];
            });

        $response = $this->controller->webhook();

        self::assertSame(200, $response->getStatus());
        self::assertContains('claim', $order);
        self::assertContains('process', $order);
        self::assertLessThan(
            array_search('process', $order, true),
            array_search('claim', $order, true),
            'Replay claim must precede processWebhook to close crash-retry double-post'
        );
    }

    public function testWebhookReleasesReplayClaimWhenProcessFails(): void
    {
        [$timestamp, $signature] = $this->prepareGenericSignedJson([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ]);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/json'],
            ['X-Webhook-Timestamp', $timestamp],
            ['X-Webhook-Signature', $signature],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_signing_secret' => 'secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::atLeastOnce())->method('set');
        $this->cache->expects(self::once())->method('remove')
            ->with(self::callback(static fn (string $key): bool => str_starts_with($key, 'replay:')));
        $this->inboundEmailService->expects(self::once())
            ->method('processWebhook')
            ->willReturn(['success' => false, 'error' => 'Ticket not found']);

        $response = $this->controller->webhook();

        self::assertSame(400, $response->getStatus());
        self::assertSame(['success' => false, 'error' => 'invalid_payload'], $response->getData());
    }

    public function testWebhookFailClosedWhenRateLimitLockContended(): void
    {
        [$timestamp, $signature] = $this->prepareGenericSignedJson([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ]);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnMap([
            ['X-Webhook-Token', 'abc'],
            ['Content-Type', 'application/json'],
            ['X-Webhook-Timestamp', $timestamp],
            ['X-Webhook-Signature', $signature],
            ['X-Webhook-Id', ''],
            ['Message-Id', ''],
        ]);
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'inbound_email_webhook_token' => 'abc',
                    'inbound_email_webhook_signing_secret' => 'secret',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    default => $default,
                };
            }
        );

        $locking = $this->createMock(ILockingProvider::class);
        $locking->method('acquireLock')->willThrowException(new \OCP\Lock\LockedException('busy'));

        $controller = new InboundEmailController(
            'ticketcheck',
            $this->request,
            $this->inboundEmailService,
            $this->config,
            $this->l10nFactory,
            $this->cacheFactory,
            $locking,
            $this->createMock(LoggerInterface::class),
        );
        $ref = new \ReflectionProperty(InboundEmailController::class, 'rawBody');
        $ref->setAccessible(true);
        $ref->setValue($controller, json_encode([
            'to' => 'support+123@example.com',
            'from' => 'user@example.org',
            'subject' => 'Re: ticket #123',
            'text' => 'hello',
        ], JSON_THROW_ON_ERROR));

        $this->cache->expects(self::never())->method('set');
        $this->inboundEmailService->expects(self::never())->method('processWebhook');

        $response = $controller->webhook();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(429, $response->getStatus());
        self::assertSame(['error' => 'too_many_requests'], $response->getData());
    }
}

