<?php

declare(strict_types=1);

/**
 * HTML Sanitizer Service for Knowledge Base and other rich content
 * Prevents XSS while allowing safe formatting (bold, lists, links, images)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use DOMDocument;
use DOMElement;
use DOMNode;

class HtmlSanitizerService
{
    /**
     * contenteditable / execCommand often emits <b>/<i> instead of <strong>/<em>.
     * Both are allowed; b/i are normalized to strong/em during sanitize.
     *
     * @var array<string, true>
     */
    private const ALLOWED_TAGS = [
        'p' => true,
        'br' => true,
        'strong' => true,
        'b' => true,
        'em' => true,
        'i' => true,
        'u' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
        'a' => true,
        'img' => true,
        'code' => true,
        'pre' => true,
        'blockquote' => true,
        'table' => true,
        'tr' => true,
        'td' => true,
        'th' => true,
        'thead' => true,
        'tbody' => true,
        'div' => true,
        'span' => true,
    ];

    /** @var array<string, array<string, true>> */
    private const ALLOWED_ATTRIBUTES = [
        '*' => ['class' => true],
        'a' => ['href' => true, 'title' => true, 'target' => true, 'rel' => true],
        'img' => ['src' => true, 'alt' => true, 'title' => true, 'width' => true, 'height' => true],
        'td' => ['colspan' => true, 'rowspan' => true],
        'th' => ['colspan' => true, 'rowspan' => true],
    ];

    /** @var array<string, true> */
    private const ALLOWED_HREF_SCHEMES = ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true];

    /**
     * Sanitize HTML content for safe display (KB articles, etc.)
     * - Allows only explicit safe tags
     * - Allows only explicit safe attributes per tag
     * - Removes event handlers and style attributes
     * - Validates href/src URLs with strict scheme and encoding checks
     */
    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="ticketcheck-sanitizer-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return '';
        }

        $root = $dom->getElementById('ticketcheck-sanitizer-root');
        if (!$root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeNodeTree($root);
        return $this->innerHtml($root);
    }

    private function sanitizeNodeTree(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            // Normalize presentational bold/italic (from WYSIWYG execCommand) to semantic tags.
            if ($tag === 'b' || $tag === 'i') {
                $normalized = $this->renameElement($child, $tag === 'b' ? 'strong' : 'em');
                if ($normalized instanceof DOMElement) {
                    $child = $normalized;
                    $tag = strtolower($child->tagName);
                }
            }

            // Some browsers emit <span style="font-weight:bold"> / font-style:italic
            // instead of <b>/<i>. Convert those before style attributes are stripped.
            if ($tag === 'span') {
                $semantic = $this->normalizeStyledSpan($child);
                if ($semantic instanceof DOMElement) {
                    $child = $semantic;
                    $tag = strtolower($child->tagName);
                }
            }

            if (!isset(self::ALLOWED_TAGS[$tag])) {
                if ($tag === 'script' || $tag === 'style' || $tag === 'iframe' || $tag === 'object' || $tag === 'embed') {
                    $child->parentNode?->removeChild($child);
                    continue;
                }
                $this->unwrapElement($child);
                continue;
            }

            $this->sanitizeElementAttributes($child, $tag);
            $this->sanitizeNodeTree($child);
        }
    }

    private function sanitizeElementAttributes(DOMElement $element, string $tag): void
    {
        $attributes = [];
        foreach ($element->attributes as $attr) {
            $attributes[] = $attr;
        }

        $allowedGlobal = self::ALLOWED_ATTRIBUTES['*'];
        $allowedTag = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;

            // KB editor stores resize as CSS custom properties on style=. Promote
            // those to HTML width/height before style is stripped (CSP-safe).
            if ($name === 'style' && $tag === 'img') {
                $this->applyImageDimensionsFromStyle($element, $value);
                $element->removeAttributeNode($attribute);
                continue;
            }

            $isAllowed = isset($allowedGlobal[$name]) || isset($allowedTag[$name]);
            if (!$isAllowed || str_starts_with($name, 'on')) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            if ($name === 'href') {
                if (!$this->isSafeUrl($value, true)) {
                    $element->setAttribute('href', '#');
                    continue;
                }
                $element->setAttribute('href', $value);
            } elseif ($name === 'src') {
                if (!$this->isSafeUrl($value, false)) {
                    $element->removeAttribute('src');
                    continue;
                }
                $element->setAttribute('src', $value);
            } elseif ($name === 'width' || $name === 'height') {
                if (!$this->isSafeImageDimension($value)) {
                    $element->removeAttribute($name);
                } else {
                    $element->setAttribute($name, (string)(int)trim($value));
                }
            } elseif ($name === 'target') {
                $normalized = strtolower(trim($value));
                if ($normalized !== '_blank' && $normalized !== '_self') {
                    $element->removeAttribute('target');
                }
            }
        }

        if ($tag === 'img') {
            $this->ensureImageDisplayClass($element);
        }

        if ($tag === 'a' && strtolower(trim($element->getAttribute('target'))) === '_blank') {
            $existingRel = strtolower(trim($element->getAttribute('rel')));
            $relParts = array_filter(explode(' ', $existingRel));
            $required = ['noopener', 'noreferrer'];
            foreach ($required as $needed) {
                if (!in_array($needed, $relParts, true)) {
                    $relParts[] = $needed;
                }
            }
            $element->setAttribute('rel', trim(implode(' ', $relParts)));
        }
    }

    private function isSafeUrl(string $url, bool $isHref): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return false;
        }

        $decoded = html_entity_decode($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/[\x00-\x1F\x7F]+/u', '', $decoded) ?? '';
        $schemeProbe = strtolower(preg_replace('/\s+/u', '', $decoded) ?? '');

        if ($schemeProbe === '') {
            return false;
        }

        if (
            str_starts_with($schemeProbe, 'javascript:') ||
            str_starts_with($schemeProbe, 'data:') ||
            str_starts_with($schemeProbe, 'vbscript:') ||
            str_starts_with($schemeProbe, 'file:') ||
            str_starts_with($schemeProbe, 'about:')
        ) {
            return false;
        }

        $parsedScheme = parse_url($decoded, PHP_URL_SCHEME);
        if (!is_string($parsedScheme) || $parsedScheme === '') {
            // Protocol-relative URLs (//evil.example/…) look “relative” to parse_url
            // but resolve against the page scheme — treat as external/unsafe.
            if (str_starts_with($schemeProbe, '//') || str_starts_with($decoded, '//')) {
                return false;
            }
            if (!$isHref) {
                return $this->isSafeKbImageSrc($decoded);
            }
            // Relative URL: allow internal paths, fragments, and query links.
            return str_starts_with($decoded, '/') ||
                str_starts_with($decoded, './') ||
                str_starts_with($decoded, '../') ||
                str_starts_with($decoded, '#') ||
                str_starts_with($decoded, '?');
        }

        $scheme = strtolower($parsedScheme);
        if ($isHref) {
            return isset(self::ALLOWED_HREF_SCHEMES[$scheme]);
        }
        // Absolute/externally-schemed src URLs are never embeddable — only
        // relative paths pass (handled above via isSafeKbImageSrc).
        return false;
    }

    /**
     * Promote KB editor CSS custom properties to HTML width/height attributes.
     */
    private function applyImageDimensionsFromStyle(DOMElement $element, string $style): void
    {
        if (
            preg_match('/--image-width:\s*(\d+(?:\.\d+)?)px/i', $style, $widthMatch) === 1
            && $this->isSafeImageDimension($widthMatch[1])
        ) {
            $element->setAttribute('width', (string)(int)round((float)$widthMatch[1]));
        }
        if (
            preg_match('/--image-height:\s*(\d+(?:\.\d+)?)px/i', $style, $heightMatch) === 1
            && $this->isSafeImageDimension($heightMatch[1])
        ) {
            $element->setAttribute('height', (string)(int)round((float)$heightMatch[1]));
        }
    }

    private function isSafeImageDimension(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !preg_match('/^\d{1,4}(?:\.\d+)?$/', $trimmed)) {
            return false;
        }
        $n = (int)round((float)$trimmed);
        return $n >= 1 && $n <= 5000;
    }

    private function ensureImageDisplayClass(DOMElement $element): void
    {
        $existing = trim($element->getAttribute('class'));
        $parts = $existing === '' ? [] : preg_split('/\s+/', $existing);
        if (!is_array($parts)) {
            $parts = [];
        }
        if (!in_array('kb-editor-image', $parts, true)) {
            $parts[] = 'kb-editor-image';
        }
        $element->setAttribute('class', implode(' ', $parts));
    }

    /**
     * Only TicketCheck KB image routes / filenames (no third-party pixels).
     */
    private function isSafeKbImageSrc(string $url): bool
    {
        if (preg_match('/^kb_[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)$/i', $url) === 1) {
            return true;
        }
        if (preg_match('#(?:^|/)apps/ticketcheck/(?:portal/)?kb/images/#i', $url) !== 1) {
            return false;
        }
        return preg_match('/kb_[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)/i', $url) === 1;
    }

    private function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    /**
     * Replace an element with a same-content element of another tag name.
     * Used to normalize <b>/<i> from contenteditable to <strong>/<em>.
     */
    private function renameElement(DOMElement $element, string $newTag): ?DOMElement
    {
        $doc = $element->ownerDocument;
        $parent = $element->parentNode;
        if ($doc === null || $parent === null) {
            return null;
        }

        $replacement = $doc->createElement($newTag);
        if (!$replacement instanceof DOMElement) {
            return null;
        }

        while ($element->firstChild !== null) {
            $replacement->appendChild($element->firstChild);
        }
        foreach (iterator_to_array($element->attributes ?? []) as $attr) {
            // Drop style when promoting to semantic tags — the tag carries the meaning.
            if (strtolower($attr->name) === 'style') {
                continue;
            }
            $replacement->setAttribute($attr->name, $attr->value);
        }
        $parent->replaceChild($replacement, $element);
        return $replacement;
    }

    /**
     * Convert styled spans from contenteditable into semantic strong/em when
     * the style only encodes bold and/or italic. Mixed styles (e.g. color) are
     * left as spans so unsafe style can be stripped later without inventing
     * formatting we cannot safely preserve.
     */
    private function normalizeStyledSpan(DOMElement $element): ?DOMElement
    {
        $style = strtolower(trim($element->getAttribute('style')));
        if ($style === '') {
            return null;
        }

        $isBold = (bool)preg_match('/(?:^|;)\s*font-weight\s*:\s*(bold|[7-9]00)\s*(;|$)/i', $style);
        $isItalic = (bool)preg_match('/(?:^|;)\s*font-style\s*:\s*italic\s*(;|$)/i', $style);
        if (!$isBold && !$isItalic) {
            return null;
        }

        // Only promote when style is solely bold/italic (plus whitespace/semicolons).
        $remainder = preg_replace(
            '/(?:^|;)\s*(?:font-weight\s*:\s*(?:bold|[7-9]00)|font-style\s*:\s*italic)\s*/i',
            '',
            $style
        );
        $remainder = trim((string)$remainder, " \t\n\r\0\x0B;");
        if ($remainder !== '') {
            return null;
        }

        if ($isBold && $isItalic) {
            // Nest em inside strong for combined formatting.
            $strong = $this->renameElement($element, 'strong');
            if (!$strong instanceof DOMElement) {
                return null;
            }
            $doc = $strong->ownerDocument;
            if ($doc === null) {
                return $strong;
            }
            $em = $doc->createElement('em');
            while ($strong->firstChild !== null) {
                $em->appendChild($strong->firstChild);
            }
            $strong->appendChild($em);
            return $strong;
        }

        return $this->renameElement($element, $isBold ? 'strong' : 'em');
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $fragment = $element->ownerDocument?->saveHTML($child);
            if (is_string($fragment)) {
                $html .= $fragment;
            }
        }
        return $html;
    }
}
