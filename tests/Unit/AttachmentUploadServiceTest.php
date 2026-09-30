<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class AttachmentUploadServiceTest extends TestCase
{
    private AttachmentUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->createMock(IConfig::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key) => $key);
        $l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);
        $this->service = new AttachmentUploadService($config, $l10nFactory);
    }

    public function testValidateUploadedFileRejectsDangerousExtension(): void
    {
        $result = $this->service->validateUploadedFile([
            'name' => 'payload.php',
            'tmp_name' => __FILE__,
            'size' => 200,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('file_type_not_allowed', $result['message']);
    }

    public function testValidateUploadedFileAcceptsPlainTextFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tc_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'ticket attachment');
        try {
            $result = $this->service->validateUploadedFile([
                'name' => 'note.txt',
                'tmp_name' => $tmp,
                'size' => filesize($tmp),
            ]);
            $this->assertTrue($result['success']);
            $this->assertSame('note.txt', $result['originalName']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testValidateUploadedFilesBatchRejectsTooManyFilesBeforeTicketCreation(): void
    {
        $files = [
            'name' => [],
            'tmp_name' => [],
            'size' => [],
            'error' => [],
        ];

        for ($i = 0; $i < 11; $i++) {
            $files['name'][] = 'note-' . $i . '.txt';
            $files['tmp_name'][] = __FILE__;
            $files['size'][] = 100;
            $files['error'][] = UPLOAD_ERR_OK;
        }

        $result = $this->service->validateUploadedFilesBatch($files);

        $this->assertFalse($result['success']);
        $this->assertSame('upload_limit_exceeded', $result['message']);
    }

    public function testValidateUploadedFilesBatchAcceptsEmptyUploadField(): void
    {
        $result = $this->service->validateUploadedFilesBatch([
            'name' => [''],
            'tmp_name' => [''],
            'size' => [0],
            'error' => [UPLOAD_ERR_NO_FILE],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['fileCount']);
    }
}
