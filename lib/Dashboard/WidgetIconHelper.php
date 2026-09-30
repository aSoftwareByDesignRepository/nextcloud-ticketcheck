<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TicketCheck
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Dashboard;

use OCA\Ticketcheck\AppInfo\Application;
use OCP\IURLGenerator;
use RuntimeException;

/**
 * Dashboard / desklet icon URLs for all Nextcloud themes.
 *
 * Per {@see \OCP\Dashboard\IIconWidget}: icons must be black (or uncoloured);
 * clients apply {@code --background-invert-if-dark}. List rows use the same
 * asset via {@see desklet-nextcloud.css} because the dashboard Vue item
 * component is unreliable for SVG {@code img} load events.
 */
final class WidgetIconHelper {
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getAbsoluteIconUrl(): string {
		foreach (['app-dashboard.svg', 'app-dark.svg', 'app.svg'] as $iconFile) {
			try {
				return $this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, $iconFile)
				);
			} catch (RuntimeException) {
				// Try next candidate.
			}
		}

		try {
			return $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath('core', 'actions/tag.svg')
			);
		} catch (RuntimeException) {
			return '';
		}
	}
}
