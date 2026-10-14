<?php

declare(strict_types=1);

/**
 * Thrown when a ticket category delete is attempted while tickets or
 * inbound routing rules still reference the key — the caller must supply
 * a reassignment target (see SPEC §US-004).
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Exception;

class CategoryReassignRequiredException extends \RuntimeException
{
	public function __construct(
		private readonly int $ticketCount,
		private readonly int $routingCount,
	) {
		parent::__construct('Category is still referenced and requires a reassignment target', 422);
	}

	public function getTicketCount(): int
	{
		return $this->ticketCount;
	}

	public function getRoutingCount(): int
	{
		return $this->routingCount;
	}
}
