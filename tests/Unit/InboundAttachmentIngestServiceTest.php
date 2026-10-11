<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\InboundAttachmentIngestService;
use OCA\Ticketcheck\Service\TicketService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InboundAttachmentIngestServiceTest extends TestCase
{
	private function service(?AttachmentUploadService $upload = null): InboundAttachmentIngestService
	{
		return new InboundAttachmentIngestService(
			$upload ?? $this->createMock(AttachmentUploadService::class),
			$this->createMock(TicketService::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function part(array $overrides = []): array
	{
		return array_merge([
			'partId' => '2',
			'filename' => 'a.pdf',
			'size' => 1000,
			'encoding' => 'base64',
			'inline' => false,
		], $overrides);
	}

	// ---------- planFetch ----------

	public function testPlanFetchAccountsBase64Inflation(): void
	{
		// Decoded 12MiB → ~16MiB wire. 12MiB+ε base64 must exceed the part cap
		// even though the declared size is under it.
		$max = InboundAttachmentIngestService::MAX_PART_WIRE_BYTES;
		$plan = InboundAttachmentIngestService::planFetch([
			$this->part(['size' => (int)($max * 0.76)]), // decoded < cap, wire > cap
		]);
		self::assertSame('part_too_large', $plan['rejected'][0]['reason']);
		self::assertSame([], $plan['fetch']);
	}

	public function testPlanFetchRespectsPartCountCap(): void
	{
		$parts = [];
		for ($i = 0; $i < 12; $i++) {
			$parts[] = $this->part(['partId' => (string)($i + 2), 'size' => 10]);
		}
		$plan = InboundAttachmentIngestService::planFetch($parts);
		self::assertCount(InboundAttachmentIngestService::MAX_PARTS, $plan['fetch']);
		self::assertCount(12 - InboundAttachmentIngestService::MAX_PARTS, $plan['rejected']);
		self::assertSame('too_many', $plan['rejected'][0]['reason']);
	}

	public function testPlanFetchRespectsMessageCap(): void
	{
		// Decoded 9MiB → ~12MiB wire each (under the 16MiB part cap).
		// 3 × 12MiB = 36MiB fits the 40MiB message cap; a 4th exceeds it.
		$each = 9 * 1024 * 1024;
		$plan = InboundAttachmentIngestService::planFetch([
			$this->part(['partId' => '2', 'size' => $each]),
			$this->part(['partId' => '3', 'size' => $each]),
			$this->part(['partId' => '4', 'size' => $each]),
			$this->part(['partId' => '5', 'size' => $each]),
		]);
		self::assertCount(3, $plan['fetch']);
		self::assertSame('message_too_large', $plan['rejected'][0]['reason']);
	}

	public function testPlanFetchDefaultsMissingFilename(): void
	{
		$plan = InboundAttachmentIngestService::planFetch([$this->part(['filename' => ''])]);
		self::assertSame('attachment-2.bin', $plan['fetch'][0]['name']);
	}

	public function testPlanFetchSanitizesFilename(): void
	{
		$plan = InboundAttachmentIngestService::planFetch([$this->part(['filename' => '../../etc/passwd'])]);
		self::assertSame('passwd', $plan['fetch'][0]['name']);
	}

	// ---------- decodeToTempFile ----------

	public function testDecodeBase64(): void
	{
		$tmp = InboundAttachmentIngestService::decodeToTempFile(base64_encode('payload-data'), 'base64');
		self::assertIsString($tmp);
		self::assertSame('payload-data', file_get_contents($tmp));
		unlink($tmp);
	}

	public function testDecodeBase64HandlesWireNoise(): void
	{
		// IMAP literals may fold base64 with whitespace — decoder must strip it.
		$b64 = base64_encode(str_repeat('x', 5000));
		$folded = implode("\r\n", str_split($b64, 76));
		$tmp = InboundAttachmentIngestService::decodeToTempFile($folded, 'base64');
		self::assertIsString($tmp);
		self::assertSame(str_repeat('x', 5000), file_get_contents($tmp));
		unlink($tmp);
	}

	public function testDecodeQuotedPrintable(): void
	{
		$tmp = InboundAttachmentIngestService::decodeToTempFile('Hello=20World=3D', 'quoted-printable');
		self::assertIsString($tmp);
		self::assertSame('Hello World=', file_get_contents($tmp));
		unlink($tmp);
	}

	public function testDecodeRawPassthrough(): void
	{
		$tmp = InboundAttachmentIngestService::decodeToTempFile('raw-bytes', '7bit');
		self::assertIsString($tmp);
		self::assertSame('raw-bytes', file_get_contents($tmp));
		unlink($tmp);
	}

	// ---------- prepare ----------

	public function testPrepareRejectsFetchFailures(): void
	{
		$upload = $this->createMock(AttachmentUploadService::class);
		$out = $this->service($upload)->prepare(1, [
			['partId' => '2', 'encoding' => 'base64', 'size' => 10, 'name' => 'a.txt', 'inline' => false],
		], static fn () => null);
		self::assertSame([], $out['valid']);
		self::assertSame('fetch_failed', $out['rejected'][0]['reason']);
	}

	public function testPrepareValidatesThroughUploadService(): void
	{
		$upload = $this->createMock(AttachmentUploadService::class);
		$upload->expects(self::once())->method('validateUploadedFile')
			->willReturn(['success' => false, 'message' => 'mime_rejected']);
		$out = $this->service($upload)->prepare(1, [
			['partId' => '2', 'encoding' => '7bit', 'size' => 3, 'name' => 'evil.php', 'inline' => false],
		], static fn () => 'php');
		self::assertSame([], $out['valid']);
		self::assertSame('mime_rejected', $out['rejected'][0]['reason']);
	}

	public function testPrepareKeepsValidFile(): void
	{
		$upload = $this->createMock(AttachmentUploadService::class);
		$upload->method('validateUploadedFile')->willReturn([
			'success' => true, 'originalName' => 'a.txt', 'mimeType' => 'text/plain',
		]);
		$svc = $this->service($upload);
		$out = $svc->prepare(1, [
			['partId' => '2', 'encoding' => 'base64', 'size' => 5, 'name' => 'a.txt', 'inline' => false],
		], static fn () => base64_encode('hello'));
		self::assertCount(1, $out['valid']);
		self::assertSame('hello', file_get_contents($out['valid'][0]['tmp']));
		self::assertSame('a.txt', $out['valid'][0]['name']);
		$svc->cleanup($out['valid']);
		self::assertFileDoesNotExist($out['valid'][0]['tmp']);
	}
}
