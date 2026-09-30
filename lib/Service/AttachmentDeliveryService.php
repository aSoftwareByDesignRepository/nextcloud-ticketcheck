<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IConfig;

/**
 * Secure attachment streaming (download vs inline image preview).
 */
class AttachmentDeliveryService
{
    /** MIME types allowed for inline display (must match upload whitelist). */
    private const INLINE_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private readonly IConfig $config,
        private readonly SafeFilenameService $safeFilenameService,
    ) {
    }

    public static function isPreviewableImage(Attachment $attachment): bool
    {
        if (!$attachment->isImage()) {
            return false;
        }

        return in_array($attachment->getMimeType(), self::INLINE_IMAGE_MIMES, true);
    }

    public function resolveFilesystemPath(int $ticketId, Attachment $attachment): ?string
    {
        $safePath = basename($attachment->getFilePath());
        if ($safePath === '' || $safePath === '.') {
            return null;
        }

        $dataDir = (string)$this->config->getSystemValue('datadirectory', '');
        if ($dataDir === '') {
            return null;
        }

        $filePath = $dataDir . '/helpdesk_attachments/' . $ticketId . '/' . $safePath;
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        return $filePath;
    }

    /**
     * @return array{ok: true, response: StreamResponse}|array{ok: false, error: string, status: 404}
     */
    public function buildStreamResponse(
        Attachment $attachment,
        int $ticketId,
        bool $requestInline,
    ): array {
        $filePath = $this->resolveFilesystemPath($ticketId, $attachment);
        if ($filePath === null) {
            return ['ok' => false, 'error' => 'file_not_found_on_disk', 'status' => 404];
        }

        $disposition = self::resolveDisposition($attachment, $requestInline);
        $safeName = $this->safeFilenameService->sanitizeForContentDisposition($attachment->getFileName());

        $response = new StreamResponse($filePath);
        $response->addHeader('Content-Disposition', $disposition . '; filename="' . $safeName . '"');
        $response->addHeader('Content-Type', $attachment->getMimeType());
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->addHeader('Cache-Control', 'private, no-store, must-revalidate');
        $response->addHeader('Pragma', 'no-cache');

        return ['ok' => true, 'response' => $response];
    }

    public static function resolveDisposition(Attachment $attachment, bool $requestInline): string
    {
        if ($requestInline && self::isPreviewableImage($attachment)) {
            return 'inline';
        }

        return 'attachment';
    }
}
