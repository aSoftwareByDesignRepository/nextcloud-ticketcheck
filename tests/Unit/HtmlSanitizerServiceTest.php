<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Service\HtmlSanitizerService;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerServiceTest extends TestCase
{
    private HtmlSanitizerService $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizerService();
    }

    public function testRemovesScriptTagsAndKeepsSafeMarkup(): void
    {
        $input = '<p>Hello <strong>world</strong></p><script>alert(1)</script>';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('<p>Hello <strong>world</strong></p>', $output);
        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringNotContainsString('alert(1)', $output);
    }

    public function testRemovesEventHandlersAndStyleAttributes(): void
    {
        $input = '<p onclick="alert(1)" style="color:red" class="keep">x</p>';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('<p class="keep">x</p>', $output);
        $this->assertStringNotContainsString('onclick=', $output);
        $this->assertStringNotContainsString('style=', $output);
    }

    public function testBlocksObfuscatedJavascriptHref(): void
    {
        $input = '<a href="java&#x0A;script:alert(1)">click</a>';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('<a href="#">click</a>', $output);
        $this->assertStringNotContainsString('javascript:', strtolower($output));
    }

    public function testAllowsSafeExternalLinksAndAddsNoopenerForBlankTarget(): void
    {
        $input = '<a href="https://example.com" target="_blank">docs</a>';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('href="https://example.com"', $output);
        $this->assertStringContainsString('target="_blank"', $output);
        $this->assertStringContainsString('rel="noopener noreferrer"', $output);
    }

    public function testNormalizesPresentationalBoldAndItalicFromWysiwyg(): void
    {
        $input = '<p>Hello <b>bold</b> and <i>italic</i></p>';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('<strong>bold</strong>', $output);
        $this->assertStringContainsString('<em>italic</em>', $output);
        $this->assertStringNotContainsString('<b>', $output);
        $this->assertStringNotContainsString('<i>', $output);
    }

    public function testNormalizesStyledSpanBoldAndItalicFromWysiwyg(): void
    {
        $input = '<p>Hello <span style="font-weight: bold">bold</span> and <span style="font-style:italic">italic</span></p>';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('<strong>bold</strong>', $output);
        $this->assertStringContainsString('<em>italic</em>', $output);
        $this->assertStringNotContainsString('style=', $output);
    }

    public function testNormalizesCombinedBoldItalicSpan(): void
    {
        $input = '<p><span style="font-weight:700; font-style: italic">both</span></p>';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('<strong><em>both</em></strong>', $output);
        $this->assertStringNotContainsString('style=', $output);
    }

    public function testDoesNotPromoteColoredSpanToSemanticTags(): void
    {
        $input = '<p><span style="font-weight:bold; color:red">x</span></p>';
        $output = $this->sanitizer->sanitize($input);

        // Color is unsafe to keep; span may remain without style (text preserved).
        $this->assertStringContainsString('x', $output);
        $this->assertStringNotContainsString('style=', $output);
        $this->assertStringNotContainsString('color:', $output);
    }

    public function testBlocksDataUriImageSource(): void
    {
        $input = '<img src="data:image/svg+xml;base64,PHN2Zy8+" alt="x">';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('<img alt="x"', $output);
        $this->assertStringContainsString('class="kb-editor-image"', $output);
        $this->assertStringNotContainsString('src="data:', strtolower($output));
    }

    public function testBlocksProtocolRelativeUrls(): void
    {
        $input = '<a href="//evil.example/phish">click</a><img src="//evil.example/t.gif" alt="x">';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringNotContainsString('//evil.example', $output);
        $this->assertStringContainsString('<a href="#">click</a>', $output);
        $this->assertStringContainsString('<img alt="x"', $output);
    }

    public function testBlocksExternalImageSources(): void
    {
        $input = '<img src="https://evil.example/track.png" alt="x">';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringNotContainsString('evil.example', $output);
        $this->assertStringContainsString('<img alt="x"', $output);
    }

    public function testAllowsTicketcheckKbImageSrc(): void
    {
        $input = '<img src="/apps/ticketcheck/kb/images/kb_20260101120000_aabbccddeeff0011.png" alt="ok">';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString(
            'src="/apps/ticketcheck/kb/images/kb_20260101120000_aabbccddeeff0011.png"',
            $output
        );
        $this->assertStringContainsString('class="kb-editor-image"', $output);
    }

    public function testPromotesEditorImageCssVarsToWidthHeightAttributes(): void
    {
        $input = '<img src="/apps/ticketcheck/kb/images/kb_abc.png" alt="pic" style="--image-width: 320px; --image-height: 180px;">';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('width="320"', $output);
        $this->assertStringContainsString('height="180"', $output);
        $this->assertStringNotContainsString('style=', $output);
        $this->assertStringNotContainsString('--image-width', $output);
    }

    public function testRejectsOversizedImageDimensionAttributes(): void
    {
        $input = '<img src="/apps/ticketcheck/kb/images/kb_abc.png" alt="x" width="99999" height="12">';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringNotContainsString('width=', $output);
        $this->assertStringContainsString('height="12"', $output);
    }

    public function testAllowsRootRelativePaths(): void
    {
        $input = '<a href="/apps/ticketcheck/portal">portal</a>';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('href="/apps/ticketcheck/portal"', $output);
    }
}

