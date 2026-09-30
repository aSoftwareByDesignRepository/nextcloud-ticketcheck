<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * URL and accessible label helpers for attachment UI (staff + guest portal).
 */
final class AttachmentDisplayHelper
{
    public const INLINE_QUERY_PARAM = 'inline';

    /**
     * @param array<string, int|string> $routeParams e.g. ticketId+attachmentId or id+attachmentId
     * @return array{download: string, preview: string|null}
     */
    public static function buildUrls(
        IURLGenerator $urlGenerator,
        string $routeName,
        array $routeParams,
        Attachment $attachment,
    ): array {
        $downloadUrl = $urlGenerator->linkToRoute($routeName, $routeParams);
        $previewUrl = null;
        if (AttachmentDeliveryService::isPreviewableImage($attachment)) {
            $previewUrl = $urlGenerator->linkToRoute(
                $routeName,
                array_merge($routeParams, [self::INLINE_QUERY_PARAM => '1']),
            );
        }

        return [
            'download' => $downloadUrl,
            'preview' => $previewUrl,
        ];
    }

    public static function previewAttachmentAriaLabel(IL10N $l, string $filename): string
    {
        return strtr($l->t('preview_attachment_aria'), ['{filename}' => $filename]);
    }

    public static function imageThumbnailAlt(IL10N $l, string $filename): string
    {
        return strtr($l->t('attachment_thumbnail_alt'), ['{filename}' => $filename]);
    }
}
