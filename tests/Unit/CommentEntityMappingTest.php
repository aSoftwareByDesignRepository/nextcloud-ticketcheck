<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\Comment;
use PHPUnit\Framework\TestCase;

class CommentEntityMappingTest extends TestCase
{
    public function testFromRowAcceptsChecklistItemIdColumn(): void
    {
        $row = [
            'id' => 41,
            'ticket_id' => 59,
            'user_id' => 'root',
            'author_name' => 'root',
            'author_email' => 'root@example.com',
            'content' => 'Comment',
            'is_internal' => 0,
            'checklist_item_id' => null,
            'created_at' => '2026-04-24 16:10:41',
        ];

        $comment = Comment::fromRow($row);

        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame(59, $comment->getTicketId());
        self::assertNull($comment->getChecklistItemId());
    }
}

