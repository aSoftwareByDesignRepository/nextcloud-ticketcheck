<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

/**
 * Accessible, consistent button/link markup for TicketCheck UI.
 *
 * Variants: primary, secondary, danger, ghost (modal cancel), text (link-style).
 */
final class ButtonHtml
{
	public const VARIANT_PRIMARY = 'primary';

	public const VARIANT_SECONDARY = 'secondary';

	public const VARIANT_DANGER = 'danger';

	public const VARIANT_GHOST = 'ghost';

	public const VARIANT_TEXT = 'text';

	public const VARIANT_WARNING = 'warning';

	private const ALLOWED_VARIANTS = [
		self::VARIANT_PRIMARY,
		self::VARIANT_SECONDARY,
		self::VARIANT_DANGER,
		self::VARIANT_GHOST,
		self::VARIANT_TEXT,
		self::VARIANT_WARNING,
	];

	private const ALLOWED_SIZES = ['sm', 'lg'];

	/**
	 * @param array<string, bool|float|int|string|null> $attrs extra HTML attributes (`class` is appended)
	 */
	public static function link(
		string $href,
		string $label,
		string $variant = self::VARIANT_PRIMARY,
		array $attrs = [],
		?string $size = null,
	): string {
		$extraClass = isset($attrs['class']) ? (string)$attrs['class'] : '';
		unset($attrs['class']);
		$class = self::buildClass($variant, $size, $extraClass);
		$attrString = self::renderAttrs(array_merge(['href' => $href, 'class' => $class], $attrs));

		return '<a ' . $attrString . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
	}

	/**
	 * @param array<string, bool|float|int|string|null> $attrs extra HTML attributes (`class` is appended)
	 */
	public static function button(
		string $label,
		string $variant = self::VARIANT_PRIMARY,
		array $attrs = [],
		?string $size = null,
	): string {
		$extraClass = isset($attrs['class']) ? (string)$attrs['class'] : '';
		unset($attrs['class']);
		$type = isset($attrs['type']) ? (string)$attrs['type'] : 'button';
		unset($attrs['type']);
		$class = self::buildClass($variant, $size, $extraClass);
		$attrString = self::renderAttrs(array_merge(['type' => $type, 'class' => $class], $attrs));

		return '<button ' . $attrString . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
	}

	public static function buildClass(string $variant, ?string $size = null, string $extraClass = ''): string
	{
		if (!in_array($variant, self::ALLOWED_VARIANTS, true)) {
			throw new \InvalidArgumentException('Unknown button variant: ' . $variant);
		}

		$parts = ['helpdesk-btn', 'helpdesk-btn--' . $variant];
		if ($size !== null && $size !== '') {
			if (!in_array($size, self::ALLOWED_SIZES, true)) {
				throw new \InvalidArgumentException('Unknown button size: ' . $size);
			}
			$parts[] = 'helpdesk-btn--' . $size;
		}
		if (trim($extraClass) !== '') {
			$parts[] = trim($extraClass);
		}

		return implode(' ', $parts);
	}

	/**
	 * @param array<string, bool|float|int|string|null> $attrs
	 */
	private static function renderAttrs(array $attrs): string
	{
		$out = [];
		foreach ($attrs as $name => $value) {
			if ($value === null || $value === false) {
				continue;
			}
			if ($value === true) {
				$out[] = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');
				continue;
			}
			$out[] = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') . '="'
				. htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
		}

		return implode(' ', $out);
	}
}
