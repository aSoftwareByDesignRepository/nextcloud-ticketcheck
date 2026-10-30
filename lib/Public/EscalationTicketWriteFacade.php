<?php

declare(strict_types=1);

/**
 * Facade contract `FC-WCHK-EXPORT` — one-way escalation export INTO TicketCheck.
 *
 * Called server-side only (e.g. by WhistleCheck) after the caller has already
 * authenticated and authorized the acting officer. This facade:
 *
 *   - takes ONLY officer-authored text (the request DTO carries no source
 *     case id, reporter handle, or backlink — see COMPOSABILITY.md, the
 *     WhistleCheck exception);
 *   - mints a normal internal ticket owned by the acting user;
 *   - is idempotent per (actorUid, idempotencyKey);
 *   - never throws — every outcome is a {@see FacadeResult}.
 *
 * Actor model: the web path runs inside the officer's own session and the
 * session uid MUST equal actorUid (confused-deputy guard). Server-to-server
 * callers whose transport carries no NC session (e.g. bearer-device
 * companion APIs) pass actorUid; the facade verifies the user exists and is
 * enabled, temporarily binds it to the session for TicketService, and
 * restores the previous state in a finally block.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Public;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketCategoryService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

final class EscalationTicketWriteFacade
{
	public const FACADE_VERSION = 1;

	private const MAX_TITLE_LEN = 200;
	private const MAX_DESCRIPTION_LEN = 20000;
	private const IDEM_SCOPE = 'wchk_escalation';

	public function __construct(
		private readonly TicketService $ticketService,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IdempotencyService $idempotency,
		private readonly LoggerInterface $logger,
		// Dynamic category allowlist (builtin + admin-created keys). Null falls
		// back to the builtin enum — reachable only in hand-built instances;
		// the DI container always injects the service.
		private readonly ?TicketCategoryService $ticketCategories = null,
		private readonly ?\OCA\Ticketcheck\Service\TicketResourceService $ticketResources = null,
	) {
	}

	public function createTicket(EscalationTicketWriteRequest $req): FacadeResult
	{
		$actorUid = $req->actorUid;
		if ($actorUid === '') {
			return FacadeResult::failure('permission_denied', 'No acting user');
		}

		// Confused-deputy guard: an established session may only act for itself.
		$sessionUid = $this->userSession->getUser()?->getUID();
		if ($sessionUid !== null && $sessionUid !== $actorUid) {
			return FacadeResult::failure('permission_denied', 'Actor does not match session user');
		}

		$user = $this->userManager->get($actorUid);
		if ($user === null || !$user->isEnabled()) {
			return FacadeResult::failure('permission_denied', 'Unknown or disabled acting user');
		}

		// Portal customers are external users; guest membership wins over any
		// internal role (mirrors PermissionService doctrine) — never let a
		// guest mint internal tickets through a sibling facade.
		if ($this->groupManager->isInGroup($actorUid, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
			return FacadeResult::failure('permission_denied', 'Portal customers cannot create internal tickets');
		}

		if ($req->title === '' || mb_strlen($req->title) > self::MAX_TITLE_LEN) {
			return FacadeResult::failure('validation_failed', 'Title must be 1–200 characters', ['fields' => ['title']]);
		}
		if ($req->description === '' || mb_strlen($req->description) > self::MAX_DESCRIPTION_LEN) {
			return FacadeResult::failure('validation_failed', 'Description must be 1–20000 characters', ['fields' => ['description']]);
		}
		// Disabled keys must be rejected on every write path — matches the
		// isEnabledKey gate TicketService applies to web/portal/companion.
		$categoryOk = $this->ticketCategories !== null
			? $this->ticketCategories->isEnabledKey($req->category)
			: in_array($req->category, Ticket::getCategories(), true);
		if (!$categoryOk) {
			return FacadeResult::failure('validation_failed', 'Unknown category', ['fields' => ['category']]);
		}
		$resourceKeys = [];
		if ($req->resources !== null && $this->ticketResources !== null) {
			try {
				$resourceKeys = $this->ticketResources->resolveWriteKeys($req->resources);
			} catch (\Throwable) {
				return FacadeResult::failure('validation_failed', 'Unknown resource', ['fields' => ['resources']]);
			}
		}
		if (!in_array($req->priority, Ticket::getPriorities(), true)) {
			return FacadeResult::failure('validation_failed', 'Unknown priority', ['fields' => ['priority']]);
		}

		// A ticket row requires customer_email/customer_name — the officer is
		// the requester. When their profile has no usable email, mint a
		// provenance-explicit placeholder on the RFC 2606 reserved TLD so the
		// ticket stays valid and audits can see it came via the facade.
		$email = $user->getEMailAddress();
		if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$email = $actorUid . '+wchk-escalation@whistlecheck.invalid';
		}
		$name = trim($user->getDisplayName());
		if ($name === '') {
			$name = $actorUid;
		}

		try {
			$payload = $this->runIdempotent($actorUid, $req->idempotencyKey, function () use ($req, $user, $sessionUid, $email, $name): array {
				// TicketService requires a session user. Web calls already have
				// one (== actorUid); server-to-server calls bind actorUid here
				// and restore the previous binding even when creation throws.
				$impersonated = $sessionUid === null;
				if ($impersonated) {
					$this->userSession->setUser($user);
				}
				try {
					$ticket = $this->ticketService->createTicket([
						'title' => $req->title,
						'description' => $req->description,
						'category' => $req->category,
						'priority' => $req->priority,
						'customer_email' => $email,
						'customer_name' => $name,
						'assigned_to' => null,
					]);
				} finally {
					if ($impersonated) {
						$this->userSession->setUser(null);
					}
				}
				if ($resourceKeys !== [] && $this->ticketResources !== null) {
					// Re-validate inside the shared guard (catalog delete/disable
					// racing the earlier resolve must not land doomed keys, NN-11);
					// map-write/re-validation failure soft-fails like the web path —
					// the ticket is real with an honestly empty resource set, and the
					// idempotent replay returns this result instead of erroring into
					// a retry that would mint a duplicate ticket.
					try {
						$this->ticketResources->withResourceWriteGuard(function () use ($ticket, $resourceKeys) {
							$this->ticketResources->assertEnabledKeys($resourceKeys);
							$this->ticketResources->setForTicket((int)$ticket->getId(), $resourceKeys);
						});
					} catch (\Throwable $e) {
						$this->logger->error('EscalationTicketWriteFacade resource attach failed after create', [
							'exception' => $e,
							'ticket_id' => $ticket->getId(),
						]);
					}
				}
				return [
					'ticketId' => $ticket->getId(),
					'ticketNumber' => $ticket->getTicketNumber(),
				];
			});
		} catch (CompanionValidationException $e) {
			return FacadeResult::failure('validation_failed', 'Invalid idempotency key', ['fields' => ['idempotencyKey']]);
		} catch (\Throwable $e) {
			// Class + location only — exception text can carry bound input.
			$this->logger->error('EscalationTicketWriteFacade failed: ' . get_class($e) . ' @ ' . $e->getFile() . ':' . $e->getLine());
			return FacadeResult::failure('internal_error', 'Ticket creation failed');
		}

		if (!isset($payload['ticketId'], $payload['ticketNumber'])) {
			$this->logger->error('EscalationTicketWriteFacade: malformed idempotency payload');
			return FacadeResult::failure('internal_error', 'Ticket creation failed');
		}

		return FacadeResult::success([
			'ticketId' => (int)$payload['ticketId'],
			'ticketNumber' => (string)$payload['ticketNumber'],
		]);
	}

	/**
	 * Converge concurrent first-attempts on the winner's stored row.
	 * IdempotencyService deliberately fail-fasts a racing same-key request
	 * with CompanionConflictException (the right contract for its HTTP
	 * callers, which retry themselves). This facade's contract is
	 * convergence: bounded retry lets losers observe the winner's row —
	 * stored before the lock releases — instead of surfacing a conflict as
	 * an internal error. Five attempts ≈ 375 ms, far above a ticket insert.
	 *
	 * @param callable():array<string,mixed> $operation
	 * @return array<string,mixed>
	 */
	private function runIdempotent(string $actorUid, ?string $key, callable $operation): array
	{
		$attempts = 0;
		while (true) {
			try {
				return $this->idempotency->run($actorUid, self::IDEM_SCOPE, $key, $operation);
			} catch (CompanionConflictException $e) {
				if (++$attempts >= 5) {
					throw $e;
				}
				usleep(75_000);
			}
		}
	}
}
