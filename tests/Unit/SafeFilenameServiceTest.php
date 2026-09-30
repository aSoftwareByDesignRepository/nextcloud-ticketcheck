<?php

declare(strict_types=1);

/**
 * Unit tests for SafeFilenameService — path traversal and header injection prevention
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Service\SafeFilenameService;
use PHPUnit\Framework\TestCase;

class SafeFilenameServiceTest extends TestCase
{
    private SafeFilenameService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SafeFilenameService();
    }

    public function testPathTraversalReturnsBasenameOnly(): void
    {
        $this->assertSame('passwd', $this->service->sanitizeForContentDisposition('../../../etc/passwd'));
        /* Dot is allowed in safe chars, so basename file.txt stays file.txt */
        $this->assertSame('file.txt', $this->service->sanitizeForContentDisposition('../../folder/file.txt'));
    }

    public function testEmptyOrNullReturnsDefault(): void
    {
        $this->assertSame('download', $this->service->sanitizeForContentDisposition(''));
        $this->assertSame('download', $this->service->sanitizeForContentDisposition('   '));
        $this->assertSame('download', $this->service->sanitizeForContentDisposition(null));
    }

    public function testCrlfInjectionStripped(): void
    {
        $result = $this->service->sanitizeForContentDisposition("file\r\nname.txt");
        $this->assertStringNotContainsString("\r", $result);
        $this->assertStringNotContainsString("\n", $result);
    }

    public function testQuotesAndBackslashStripped(): void
    {
        /* Quotes and backslash removed; dot is allowed → filename.txt */
        $this->assertSame('filename.txt', $this->service->sanitizeForContentDisposition('file"name\\\'.\'txt'));
    }

    public function testUnsafeCharsReplacedWithUnderscore(): void
    {
        $this->assertSame('a_b_c', $this->service->sanitizeForContentDisposition('a b c'));
        /* Dot is in allowed set [a-zA-Z0-9._-], so file.txt is unchanged */
        $this->assertSame('file.txt', $this->service->sanitizeForContentDisposition('file.txt'));
    }

    public function testValidFilenamePreserved(): void
    {
        $this->assertSame('document.pdf', $this->service->sanitizeForContentDisposition('document.pdf'));
        $this->assertSame('my-file_2024', $this->service->sanitizeForContentDisposition('my-file_2024'));
    }

    public function testMaxLengthEnforced(): void
    {
        $long = str_repeat('a', 300);
        $result = $this->service->sanitizeForContentDisposition($long);
        $this->assertLessThanOrEqual(255, strlen($result));
    }

    public function testOnlyDotReturnsDefault(): void
    {
        $this->assertSame('download', $this->service->sanitizeForContentDisposition('.'));
    }
}
