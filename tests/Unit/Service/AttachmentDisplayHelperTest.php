<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Service\AttachmentDisplayHelper;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class AttachmentDisplayHelperTest extends TestCase
{
    public function testBuildUrlsAddsInlineQueryForImagesOnly(): void
    {
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRoute')->willReturnCallback(
            static function (string $route, array $params): string {
                $query = http_build_query(array_diff_key($params, ['ticketId' => 1, 'attachmentId' => 1, 'id' => 1]));
                $base = '/apps/ticketcheck/tickets/1/attachments/2';
                return $query !== '' ? $base . '?' . $query : $base;
            },
        );

        $image = new Attachment();
        $image->setMimeType('image/jpeg');

        $urls = AttachmentDisplayHelper::buildUrls(
            $urlGenerator,
            'ticketcheck.ticket.downloadAttachment',
            ['ticketId' => 1, 'attachmentId' => 2],
            $image,
        );
        $this->assertStringNotContainsString('inline', $urls['download']);
        $this->assertNotNull($urls['preview']);
        $this->assertStringContainsString('inline=1', $urls['preview']);

        $pdf = new Attachment();
        $pdf->setMimeType('application/pdf');
        $pdfUrls = AttachmentDisplayHelper::buildUrls(
            $urlGenerator,
            'ticketcheck.ticket.downloadAttachment',
            ['ticketId' => 1, 'attachmentId' => 3],
            $pdf,
        );
        $this->assertNull($pdfUrls['preview']);
    }
}
