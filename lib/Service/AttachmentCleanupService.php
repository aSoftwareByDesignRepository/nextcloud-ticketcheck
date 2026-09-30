<?php

declare(strict_types=1);

/**
 * Service for cleaning up attachment files and directories
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for attachment file cleanup
 */
class AttachmentCleanupService
{
    /**
     * Protect in-flight merge copies and pre-insert uploads from hourly GC.
     * Merge copies files before DB commit; uploads write bytes before the row.
     */
    private const UNREFERENCED_FILE_MIN_AGE_SECONDS = 7200;

    private IDBConnection $db;
    private AttachmentMapper $attachmentMapper;
    private LoggerInterface $logger;
    private IConfig $config;

    public function __construct(
        IDBConnection $db,
        AttachmentMapper $attachmentMapper,
        LoggerInterface $logger,
        IConfig $config
    ) {
        $this->db = $db;
        $this->attachmentMapper = $attachmentMapper;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Clean up all attachments for a ticket
     *
     * @param int $ticketId
     * @return array
     */
    public function cleanupTicketAttachments(int $ticketId): array
    {
        $attachments = $this->attachmentMapper->findByTicketId($ticketId);
        $result = [
            'files_deleted' => 0,
            'files_failed' => 0,
            'directories_cleaned' => 0,
            'errors' => []
        ];

        if (empty($attachments)) {
            return $result;
        }

        $dataDir = $this->config->getSystemValue('datadirectory', '');
        $ticketDir = $dataDir . '/helpdesk_attachments/' . $ticketId;

        foreach ($attachments as $attachment) {
            $safePath = basename($attachment->getFilePath());
            if ($safePath === '' || $safePath === '.') {
                continue;
            }
            $filePath = $ticketDir . '/' . $safePath;

            if (file_exists($filePath)) {
                if (unlink($filePath)) {
                    $result['files_deleted']++;
                    $this->logger->debug('Deleted attachment file', [
                        'ticket_id' => $ticketId,
                        'file_path' => $filePath,
                        'attachment_id' => $attachment->getId()
                    ]);
                } else {
                    $result['files_failed']++;
                    $result['errors'][] = "Failed to delete file: $filePath";
                    $this->logger->error('Failed to delete attachment file', [
                        'ticket_id' => $ticketId,
                        'file_path' => $filePath,
                        'attachment_id' => $attachment->getId()
                    ]);
                }
            } else {
                $this->logger->warning('Attachment file not found', [
                    'ticket_id' => $ticketId,
                    'file_path' => $filePath,
                    'attachment_id' => $attachment->getId()
                ]);
            }
        }

        // Clean up empty directories
        $result['directories_cleaned'] = $this->cleanupEmptyDirectories($ticketDir);

        return $result;
    }

    /**
     * Clean up attachments for a comment
     *
     * @param int $commentId
     * @return array
     */
    public function cleanupCommentAttachments(int $commentId): array
    {
        $attachments = $this->attachmentMapper->findByCommentId($commentId);
        $result = [
            'files_deleted' => 0,
            'files_failed' => 0,
            'directories_cleaned' => 0,
            'errors' => []
        ];

        if (empty($attachments)) {
            return $result;
        }

        $dataDir = $this->config->getSystemValue('datadirectory', '');
        $commentDir = $dataDir . '/helpdesk_attachments/comments/' . $commentId;

        foreach ($attachments as $attachment) {
            $safePath = basename($attachment->getFilePath());
            if ($safePath === '' || $safePath === '.') {
                continue;
            }
            $filePath = $commentDir . '/' . $safePath;

            if (file_exists($filePath)) {
                if (unlink($filePath)) {
                    $result['files_deleted']++;
                    $this->logger->debug('Deleted comment attachment file', [
                        'comment_id' => $commentId,
                        'file_path' => $filePath,
                        'attachment_id' => $attachment->getId()
                    ]);
                } else {
                    $result['files_failed']++;
                    $result['errors'][] = "Failed to delete file: $filePath";
                    $this->logger->error('Failed to delete comment attachment file', [
                        'comment_id' => $commentId,
                        'file_path' => $filePath,
                        'attachment_id' => $attachment->getId()
                    ]);
                }
            } else {
                $this->logger->warning('Comment attachment file not found', [
                    'comment_id' => $commentId,
                    'file_path' => $filePath,
                    'attachment_id' => $attachment->getId()
                ]);
            }
        }

        // Clean up empty directories
        $result['directories_cleaned'] = $this->cleanupEmptyDirectories($commentDir);

        return $result;
    }

    /**
     * Clean up a specific attachment file
     *
     * @param int $attachmentId
     * @return array
     */
    public function cleanupAttachment(int $attachmentId): array
    {
        $attachment = $this->attachmentMapper->find($attachmentId);
        $result = [
            'files_deleted' => 0,
            'files_failed' => 0,
            'directories_cleaned' => 0,
            'errors' => []
        ];

        $dataDir = $this->config->getSystemValue('datadirectory', '');
        $safePath = basename($attachment->getFilePath());
        if ($safePath === '' || $safePath === '.') {
            $result['errors'][] = 'Invalid attachment file path';
            return $result;
        }

        if ($attachment->getTicketId()) {
            $filePath = $dataDir . '/helpdesk_attachments/' . $attachment->getTicketId() . '/' . $safePath;
        } elseif ($attachment->getCommentId()) {
            $filePath = $dataDir . '/helpdesk_attachments/comments/' . $attachment->getCommentId() . '/' . $safePath;
        } else {
            $result['errors'][] = 'Invalid attachment: no ticket or comment ID';
            return $result;
        }

        if (file_exists($filePath)) {
            if (unlink($filePath)) {
                $result['files_deleted']++;
                $this->logger->debug('Deleted attachment file', [
                    'attachment_id' => $attachmentId,
                    'file_path' => $filePath
                ]);
            } else {
                $result['files_failed']++;
                $result['errors'][] = "Failed to delete file: $filePath";
                $this->logger->error('Failed to delete attachment file', [
                    'attachment_id' => $attachmentId,
                    'file_path' => $filePath
                ]);
            }
        } else {
            $this->logger->warning('Attachment file not found', [
                'attachment_id' => $attachmentId,
                'file_path' => $filePath
            ]);
        }

        // Clean up empty directories
        $directory = dirname($filePath);
        $result['directories_cleaned'] = $this->cleanupEmptyDirectories($directory);

        return $result;
    }

    /**
     * Clean up empty directories recursively
     *
     * @param string $directory
     * @return int Number of directories removed
     */
    private function cleanupEmptyDirectories(string $directory): int
    {
        $removed = 0;

        if (!is_dir($directory)) {
            return $removed;
        }

        // Check if directory is empty
        $files = scandir($directory);
        if ($files === false) {
            return $removed;
        }

        // Remove . and .. entries
        $files = array_diff($files, ['.', '..']);

        if (empty($files)) {
            if (rmdir($directory)) {
                $removed++;
                $this->logger->debug('Removed empty directory', ['directory' => $directory]);

                // Try to clean up parent directory
                $parent = dirname($directory);
                if ($parent !== $directory && $parent !== '/') {
                    $removed += $this->cleanupEmptyDirectories($parent);
                }
            } else {
                $this->logger->warning('Failed to remove empty directory', ['directory' => $directory]);
            }
        }

        return $removed;
    }

    /**
     * Delete on-disk files under helpdesk_attachments/{ticketId}/ that are not
     * referenced by any attachment row for that ticket (e.g. merge copy orphans
     * left after a hard crash between copy and DB commit).
     *
     * @return int Number of files deleted
     */
    public function cleanupUnreferencedFilesForTicket(int $ticketId): int
    {
        if ($ticketId <= 0) {
            return 0;
        }

        $dataDir = (string) $this->config->getSystemValue('datadirectory', '');
        if ($dataDir === '') {
            return 0;
        }

        $ticketDir = rtrim($dataDir, '/') . '/helpdesk_attachments/' . $ticketId;
        if (!is_dir($ticketDir)) {
            return 0;
        }

        $referenced = [];
        foreach ($this->attachmentMapper->findByTicketId($ticketId) as $attachment) {
            $safe = basename((string) $attachment->getFilePath());
            if ($safe !== '' && $safe !== '.') {
                $referenced[$safe] = true;
            }
        }

        $deleted = 0;
        $entries = scandir($ticketDir);
        if ($entries === false) {
            return 0;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $ticketDir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            if (isset($referenced[$entry])) {
                continue;
            }
            // Skip young files — may be merge copies or uploads not yet committed to DB.
            $mtime = @filemtime($path);
            if ($mtime !== false && (time() - $mtime) < self::UNREFERENCED_FILE_MIN_AGE_SECONDS) {
                continue;
            }
            if (@unlink($path)) {
                $deleted++;
                $this->logger->info('Deleted unreferenced attachment file', [
                    'ticket_id' => $ticketId,
                    'file' => $entry,
                ]);
            }
        }

        if ($deleted > 0) {
            $this->cleanupEmptyDirectories($ticketDir);
        }

        return $deleted;
    }

    /**
     * Sweep numeric ticket attachment dirs for unreferenced files (merge crash orphans).
     *
     * @return int Total files deleted
     */
    public function cleanupUnreferencedAttachmentFiles(int $maxTicketDirs = 500): int
    {
        $dataDir = (string) $this->config->getSystemValue('datadirectory', '');
        if ($dataDir === '') {
            return 0;
        }

        $root = rtrim($dataDir, '/') . '/helpdesk_attachments';
        if (!is_dir($root)) {
            return 0;
        }

        $entries = scandir($root);
        if ($entries === false) {
            return 0;
        }

        $deleted = 0;
        $scanned = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'comments') {
                continue;
            }
            if (!ctype_digit($entry)) {
                continue;
            }
            $scanned++;
            if ($scanned > $maxTicketDirs) {
                break;
            }
            $deleted += $this->cleanupUnreferencedFilesForTicket((int) $entry);
        }

        return $deleted;
    }

    /**
     * Get cleanup statistics for a ticket
     *
     * @param int $ticketId
     * @return array
     */
    public function getTicketCleanupStats(int $ticketId): array
    {
        $attachments = $this->attachmentMapper->findByTicketId($ticketId);
        $dataDir = $this->config->getSystemValue('datadirectory', '');
        $ticketDir = $dataDir . '/helpdesk_attachments/' . $ticketId;

        $stats = [
            'total_attachments' => count($attachments),
            'files_exist' => 0,
            'files_missing' => 0,
            'total_size' => 0,
            'directory_exists' => is_dir($ticketDir)
        ];

        foreach ($attachments as $attachment) {
            $safePath = basename($attachment->getFilePath());
            if ($safePath === '' || $safePath === '.') {
                continue;
            }
            $filePath = $ticketDir . '/' . $safePath;
            if (file_exists($filePath)) {
                $stats['files_exist']++;
                $stats['total_size'] += filesize($filePath);
            } else {
                $stats['files_missing']++;
            }
        }

        return $stats;
    }

    /**
     * Delete on-disk ticket attachment files that have no matching DB row.
     * Recovers from hard crashes mid-merge (copy-then-commit left orphan copies).
     *
     * @return int Number of files deleted
     */
    public function cleanupUnreferencedTicketAttachmentFiles(): int
    {
        $dataDir = (string) $this->config->getSystemValue('datadirectory', '');
        $root = rtrim($dataDir, '/') . '/helpdesk_attachments';
        if ($dataDir === '' || !is_dir($root)) {
            return 0;
        }

        $deleted = 0;
        $entries = scandir($root);
        if ($entries === false) {
            return 0;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'comments') {
                continue;
            }
            if (!ctype_digit($entry)) {
                continue;
            }
            $ticketId = (int) $entry;
            $ticketDir = $root . '/' . $entry;
            if (!is_dir($ticketDir)) {
                continue;
            }

            $referenced = [];
            foreach ($this->attachmentMapper->findByTicketId($ticketId) as $attachment) {
                $safe = basename((string) $attachment->getFilePath());
                if ($safe !== '' && $safe !== '.') {
                    $referenced[$safe] = true;
                }
            }

            $files = scandir($ticketDir);
            if ($files === false) {
                continue;
            }
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $ticketDir . '/' . $file;
                if (!is_file($path) || isset($referenced[$file])) {
                    continue;
                }
                if (@unlink($path)) {
                    $deleted++;
                    $this->logger->info('Deleted unreferenced attachment file', [
                        'ticket_id' => $ticketId,
                        'file' => $file,
                    ]);
                }
            }

            $this->cleanupEmptyDirectories($ticketDir);
        }

        return $deleted;
    }

    /**
     * Verify attachment file integrity
     *
     * @param int $attachmentId
     * @return array
     */
    public function verifyAttachment(int $attachmentId): array
    {
        $attachment = $this->attachmentMapper->find($attachmentId);
        $dataDir = $this->config->getSystemValue('datadirectory', '');
        $safePath = basename($attachment->getFilePath());
        if ($safePath === '' || $safePath === '.') {
            return [
                'valid' => false,
                'error' => 'Invalid attachment file path'
            ];
        }

        if ($attachment->getTicketId()) {
            $filePath = $dataDir . '/helpdesk_attachments/' . $attachment->getTicketId() . '/' . $safePath;
        } elseif ($attachment->getCommentId()) {
            $filePath = $dataDir . '/helpdesk_attachments/comments/' . $attachment->getCommentId() . '/' . $safePath;
        } else {
            return [
                'valid' => false,
                'error' => 'Invalid attachment: no ticket or comment ID'
            ];
        }

        $result = [
            'valid' => true,
            'file_exists' => file_exists($filePath),
            'file_path' => $filePath,
            'expected_size' => $attachment->getFileSize(),
            'actual_size' => 0,
            'size_matches' => false
        ];

        if ($result['file_exists']) {
            $result['actual_size'] = filesize($filePath);
            $result['size_matches'] = $result['actual_size'] === $attachment->getFileSize();
        }

        return $result;
    }
}
