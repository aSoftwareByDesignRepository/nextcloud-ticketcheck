<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCP\IConfig;
use OCP\L10N\IFactory;

class AttachmentUploadService
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;
    private const MAX_TOTAL_FILES = 10;
    private const MAX_TOTAL_SIZE = 25 * 1024 * 1024;
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf',
        'doc', 'docx', 'odt',
        'xls', 'xlsx', 'ods',
        'ppt', 'pptx', 'odp',
        'txt', 'rtf',
        'zip', '7z',
    ];
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'text/plain',
        'text/rtf',
        'application/rtf',
        'application/zip',
        'application/x-zip-compressed',
        'application/x-7z-compressed',
    ];
    private const DANGEROUS_EXTENSIONS = ['php', 'phtml', 'php3', 'php4', 'php5', 'pht', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'vbs', 'js', 'jar', 'app'];

    public function __construct(
        private readonly IConfig $config,
        private readonly IFactory $l10nFactory,
    ) {
    }

    /**
     * @param array{name?:mixed,tmp_name?:mixed,size?:mixed} $file
     * @return array{success:bool,message?:string,mimeType?:string,originalName?:string}
     */
    public function validateUploadedFile(array $file): array
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!isset($file['size'], $file['name'], $file['tmp_name'])) {
            return ['success' => false, 'message' => $l->t('invalid_parameters')];
        }
        if ((int)$file['size'] > self::MAX_FILE_SIZE) {
            return ['success' => false, 'message' => strtr($l->t('file_too_large'), ['{filename}' => (string)$file['name']])];
        }

        $originalName = basename((string)$file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return ['success' => false, 'message' => $l->t('file_type_not_allowed')];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, (string)$file['tmp_name']) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return ['success' => false, 'message' => $l->t('invalid_file_type_detected')];
        }

        if (substr_count($originalName, '.') > 1) {
            $parts = explode('.', strtolower($originalName));
            $lastPart = (string)end($parts);
            $secondLastPart = count($parts) > 1 ? (string)$parts[count($parts) - 2] : '';
            if (in_array($lastPart, self::DANGEROUS_EXTENSIONS, true) || in_array($secondLastPart, self::DANGEROUS_EXTENSIONS, true)) {
                return ['success' => false, 'message' => $l->t('multiple_extensions_not_allowed')];
            }
        }

        foreach (self::DANGEROUS_EXTENSIONS as $dangerousExtension) {
            if (stripos($originalName, '.' . $dangerousExtension) !== false) {
                return ['success' => false, 'message' => strtr($l->t('dangerous_extension_blocked'), ['{extension}' => $dangerousExtension])];
            }
        }

        return [
            'success' => true,
            'mimeType' => $mimeType,
            'originalName' => $originalName,
        ];
    }

    /**
     * Validate a PHP multiple-upload array before creating the owning ticket/comment.
     *
     * @param array{name?:mixed,tmp_name?:mixed,size?:mixed,error?:mixed,type?:mixed} $files
     * @return array{success:bool,message?:string,fileCount?:int,totalSize?:int}
     */
    public function validateUploadedFilesBatch(array $files): array
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!isset($files['name'], $files['tmp_name'], $files['size'], $files['error']) || !is_array($files['name'])) {
            return ['success' => true, 'fileCount' => 0, 'totalSize' => 0];
        }

        $fileCount = count($files['name']);
        $acceptedCount = 0;
        $totalSize = 0;
        $normalizedFiles = [];

        for ($i = 0; $i < $fileCount; $i++) {
            $name = (string)($files['name'][$i] ?? '');
            $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);

            if ($name === '' && $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => $l->t('file_upload_failed_try_again')];
            }

            $acceptedCount++;
            if ($acceptedCount > self::MAX_TOTAL_FILES) {
                return ['success' => false, 'message' => $l->t('upload_limit_exceeded')];
            }

            $size = (int)($files['size'][$i] ?? 0);
            $totalSize += $size;
            if ($totalSize > self::MAX_TOTAL_SIZE) {
                return ['success' => false, 'message' => $l->t('upload_limit_exceeded')];
            }

            $normalizedFiles[] = [
                'name' => $name,
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'size' => $size,
            ];
        }

        foreach ($normalizedFiles as $file) {
            $validation = $this->validateUploadedFile($file);
            if (($validation['success'] ?? false) !== true) {
                return ['success' => false, 'message' => $validation['message'] ?? $l->t('invalid_parameters')];
            }
        }

        return ['success' => true, 'fileCount' => $acceptedCount, 'totalSize' => $totalSize];
    }

    public function persistUploadedFile(int $ticketId, string $tmpPath, string $originalName): ?string
    {
        $dataDir = $this->config->getSystemValue('datadirectory', '');
        $uploadDir = $dataDir . '/helpdesk_attachments/' . $ticketId;
        if (!file_exists($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            return null;
        }

        $storedName = $this->buildStoredFilename($originalName);
        $filePath = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file($tmpPath, $filePath)) {
            return null;
        }

        return $storedName;
    }

    /**
     * Remove a file previously written by persistUploadedFile (orphan cleanup after failed DB insert).
     */
    public function deletePersistedFile(int $ticketId, string $storedName): void
    {
        $storedName = basename($storedName);
        if ($storedName === '' || $storedName === '.' || $storedName === '..') {
            return;
        }
        $dataDir = $this->config->getSystemValue('datadirectory', '');
        $filePath = $dataDir . '/helpdesk_attachments/' . $ticketId . '/' . $storedName;
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    private function buildStoredFilename(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $prefix = bin2hex(random_bytes(16));
        return $extension !== '' ? ($prefix . '.' . $extension) : $prefix;
    }
}
