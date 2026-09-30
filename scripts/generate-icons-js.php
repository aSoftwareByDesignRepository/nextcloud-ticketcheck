<?php

declare(strict_types=1);

/**
 * Regenerate js/common/icons.js from IconCatalog (single source of truth).
 */
$root = dirname(__DIR__);
require_once $root . '/lib/Service/IconCatalog.php';

use OCA\Ticketcheck\Service\IconCatalog;

$paths = IconCatalog::paths();
ksort($paths);

$lines = [];
foreach ($paths as $name => $body) {
	$escaped = str_replace("'", "\\'", $body);
	$lines[] = "\t\t'" . $name . "': '" . $escaped . "',";
}
$pathsJs = implode("\n", $lines);

$out = <<<JS
/**
 * Lucide icon catalog for TicketCheck (generated — do not edit by hand).
 * Run: php scripts/generate-icons-js.php
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */
(function (global) {
	'use strict';

	if (global.TicketCheckIcons && typeof global.TicketCheckIcons.hydrate === 'function') {
		return;
	}

	const SVG_OPEN = '<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon tc-icon" aria-hidden="true" focusable="false">';
	const SVG_CLOSE = '</svg>';

	const PATHS = {
{$pathsJs}
	};

	function svg(name) {
		const body = PATHS[name];
		if (!body) {
			return '';
		}
		return SVG_OPEN + body + SVG_CLOSE;
	}

	function hydrate(root) {
		const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
		scope.querySelectorAll('[data-lucide]').forEach(function (el) {
			if (el.getAttribute('data-lucide-hydrated') === '1') {
				return;
			}
			const name = el.getAttribute('data-lucide');
			const markup = svg(name);
			if (markup === '') {
				return;
			}
			el.innerHTML = markup;
			el.setAttribute('data-lucide-hydrated', '1');
		});
	}

	function list() {
		return Object.keys(PATHS).slice();
	}

	global.TicketCheckIcons = {
		svg: svg,
		hydrate: hydrate,
		list: list,
	};

	function init() {
		hydrate(document);
		if (typeof global.MutationObserver === 'function') {
			const observer = new MutationObserver(function (mutations) {
				for (let i = 0; i < mutations.length; i++) {
					const m = mutations[i];
					if (!m.addedNodes) {
						continue;
					}
					for (let j = 0; j < m.addedNodes.length; j++) {
						const node = m.addedNodes[j];
						if (node && node.nodeType === 1) {
							if (node.matches && node.matches('[data-lucide]')) {
								hydrate(node.parentNode || node);
							} else if (node.querySelectorAll) {
								hydrate(node);
							}
						}
					}
				}
			});
			observer.observe(document.documentElement, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window);

JS;

$target = $root . '/js/common/icons.js';
file_put_contents($target, $out);
echo 'Wrote ' . $target . ' (' . count($paths) . " icons)\n";
