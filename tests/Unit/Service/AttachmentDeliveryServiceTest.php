<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AttachmentDeliveryServiceTest extends TestCase
{
    private string $dataDir;
    private AttachmentDeliveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir() . '/tc-attach-' . bin2hex(random_bytes(4));
        mkdir($this->dataDir . '/helpdesk_attachments/42', 0750, true);
        file_put_contents($this->dataDir . '/helpdesk_attachments/42/abc.png', 'fake-png');

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->with('datadirectory', '')->willReturn($this->dataDir);

        $this->service = new AttachmentDeliveryService($config, new SafeFilenameService());
    }

    protected function tearDown(): void
    {
        $dir = $this->dataDir . '/helpdesk_attachments';
        if (is_dir($dir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($dir);
        }
        if (is_dir($this->dataDir)) {
            rmdir($this->dataDir);
        }
        parent::tearDown();
    }

    public function testIsPreviewableImageOnlyForWhitelistedMimes(): void
    {
        $png = new Attachment();
        $png->setMimeType('image/png');
        $this->assertTrue(AttachmentDeliveryService::isPreviewableImage($png));

        $pdf = new Attachment();
        $pdf->setMimeType('application/pdf');
        $this->assertFalse(AttachmentDeliveryService::isPreviewableImage($pdf));

        $svg = new Attachment();
        $svg->setMimeType('image/svg+xml');
        $this->assertFalse(AttachmentDeliveryService::isPreviewableImage($svg));
    }

    public function testResolveDispositionUsesInlineForPreviewableImages(): void
    {
        $attachment = new Attachment();
        $attachment->setMimeType('image/png');

        $this->assertSame('inline', AttachmentDeliveryService::resolveDisposition($attachment, true));
        $this->assertSame('attachment', AttachmentDeliveryService::resolveDisposition($attachment, false));
    }

    public function testResolveDispositionForcesAttachmentForNonImagesEvenWhenInlineRequested(): void
    {
        $attachment = new Attachment();
        $attachment->setMimeType('application/pdf');

        $this->assertSame('attachment', AttachmentDeliveryService::resolveDisposition($attachment, true));
    }

    public function testBuildStreamResponseSucceedsForExistingFile(): void
    {
        $attachment = new Attachment();
        $attachment->setFilePath('abc.png');
        $attachment->setFileName('screenshot.png');
        $attachment->setMimeType('image/png');

        $result = $this->service->buildStreamResponse($attachment, 42, true);
        $this->assertTrue($result['ok']);
    }

    public function testBuildStreamResponseRejectsPathTraversal(): void
    {
        $attachment = new Attachment();
        $attachment->setFilePath('../secret.png');
        $attachment->setFileName('secret.png');
        $attachment->setMimeType('image/png');

        $result = $this->service->buildStreamResponse($attachment, 42, false);
        $this->assertFalse($result['ok']);
        $this->assertSame(404, $result['status']);
    }
}
