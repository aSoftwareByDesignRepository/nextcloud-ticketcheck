<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\Attachment;
use PHPUnit\Framework\TestCase;

class AttachmentEntityMappingTest extends TestCase
{
    public function testFromRowAcceptsChecklistItemIdColumn(): void
    {
        $row = [
            'id' => 44,
            'ticket_id' => 56,
            'comment_id' => null,
            'file_path' => '1763911756_6923284ca9c7f_example.png',
            'file_name' => 'example.png',
            'file_size' => 12345,
            'mime_type' => 'image/png',
            'uploaded_by' => 'root',
            'uploaded_at' => '2026-04-24 16:10:41',
            'checklist_item_id' => null,
        ];

        $attachment = Attachment::fromRow($row);

        self::assertInstanceOf(Attachment::class, $attachment);
        self::assertSame(56, $attachment->getTicketId());
        self::assertNull($attachment->getChecklistItemId());
    }
}

