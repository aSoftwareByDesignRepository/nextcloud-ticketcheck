<?php

declare(strict_types=1);

/**
 * Facade contract `FC-DFC-REPORT` — structured defect-report intake INTO
 * TicketCheck from anonymous external reporters.
 *
 * Called server-side only (e.g. by DefectCheck) after the caller has already
 * run its own abuse boundary (rate-limit, honeypot, upload validation).
 * Unlike the escalation facade there is no acting NC user: reporters are
 * anonymous. This facade instead takes a guest-actor descriptor:
 *
 *   - reporterEmail present → a portal guest identity is resolved/created and
 *     bound as the ticket's actor (`portal:<id>`) — the requester-ticket path
 *     the guest portal already uses;
 *   - reporterEmail null → an actorless provenance ticket (created_by
 *     'defect-report', placeholder .invalid customer address);
 *   - staged files are caller-resolved temp descriptors ingested through the
 *     same actor-free path as inbound-mail attachments;
 *   - idempotent per idempotencyKey; never throws — every outcome is a
 *     {@see FacadeResult}.
 *
 * linkReporter() powers the post-submit "want to track this?" flow: it binds
 * a guest identity to an existing (possibly anonymous) ticket and triggers
 * the standard portal invite mail.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Public;

use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Service\GuestIdentityService;
use OCA\Ticketcheck\Service\GuestInviteService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCA\Ticketcheck\Service\TicketCategoryService;
use OCA\Ticketcheck\Service\TicketNotificationDispatcher;
use OCA\Ticketcheck\Service\TicketService;
use Psr\Log\LoggerInterface;

final class DefectReportWriteFacade
{
	public const FACADE_VERSION = 1;

	private const MAX_TITLE_LEN = 200;
	private const MAX_DESCRIPTION_LEN = 20000;
	private const MAX_NAME_LEN = 190;
	private const MAX_PHONE_LEN = 64;
	private const MAX_SLUG_LEN = 64;
	private const MAX_ATTACHMENTS = 10;
	private const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;
	private const IDEM_SCOPE = 'dfc_report';
	private const LINK_IDEM_SCOPE = 'dfc_link';
	private const ACTOR = 'defect-report';

	public function __construct(
		private readonly TicketService $ticketService,
		private readonly GuestIdentityService $identities,
		private readonly GuestInviteService $invites,
		private readonly PortalActorResolver $actorResolver,
		private readonly IdempotencyService $idempotency,
		private readonly HtmlSanitizerService $sanitizer,
		private readonly TicketNotificationDispatcher $notifier,
		private readonly LoggerInterface $logger,
		private readonly ?TicketCategoryService $ticketCategories = null,
	) {
	}

	public function createTicket(DefectReportWriteRequest $req): FacadeResult
	{
		$validation = $this->validateRequest($req);
		if ($validation !== null) {
			return $validation;
		}

		// Defense in depth (NN-09): never trust the caller already sanitized.
		$description = $this->sanitizer->sanitize($req->descriptionHtml);
		if ($description === '') {
			return FacadeResult::failure('validation_failed', 'Description must not be empty', ['fields' => ['descriptionHtml']]);
		}

		$identity = null;
		if ($req->reporterEmail !== null) {
			try {
				$identity = $this->identities->findOrCreate(
					strtolower($req->reporterEmail),
					$req->reporterDisplayName ?? $req->reporterEmail,
					self::ACTOR,
					$req->reporterLocale,
				);
			} catch (\Throwable $e) {
				$this->logger->error('DefectReportWriteFacade identity resolution failed: ' . get_class($e));
				return FacadeResult::failure('internal_error', 'Ticket creation failed');
			}
			if ($identity->getStatus() !== GuestIdentityService::STATUS_ACTIVE) {
				return FacadeResult::failure('permission_denied', 'Reporter identity is not active');
			}
		}

		try {
			$payload = $this->runIdempotent(self::IDEM_SCOPE, $req->idempotencyKey, function () use ($req, $description, $identity): array {
				$customerName = $req->reporterDisplayName
					?? ($identity !== null ? (string)$identity->getDisplayName() : '');

				if ($identity !== null) {
					// Guest-actor path: the same requester-ticket write the
					// portal runs — created_by 'portal:<id>', created_by_guest.
					// notifyCustomer=false defers the created-mail until the
					// attachment batch lands so no confirmation escapes for a
					// compensation-deleted row.
					$ticket = $this->actorResolver->withGuestActor(
						$identity,
						fn () => $this->ticketService->createTicket([
							'title' => $req->title,
							'description' => $description,
							'category' => $req->categoryKey,
							'priority' => 'normal',
							'project_id' => $req->projectId,
							'customer_email' => $req->reporterEmail,
							'customer_name' => $customerName !== '' ? $customerName : $req->reporterEmail,
							'created_by_guest' => true,
						], false)
					);
				} else {
					$ticket = $this->ticketService->createTicketFromDefectReport([
						'title' => $req->title,
						'description' => $description,
						'category' => $req->categoryKey,
						'priority' => 'normal',
						'project_id' => $req->projectId,
						'customer_name' => $customerName,
						'reference_code' => $req->internalReportRef,
					]);
				}

				try {
					$this->ingestStagedFiles((int)$ticket->getId(), $req->stagedFiles);
				} catch (\Throwable $e) {
					// Compensate like the portal create path: never leave an
					// orphan ticket when the attach batch fails.
					try {
						$this->ticketService->deleteTicket((int)$ticket->getId());
					} catch (\Throwable $cleanup) {
						$this->logger->error('DefectReportWriteFacade attach failed; ticket compensation delete failed: ' . get_class($cleanup));
					}
					throw $e;
				}

				if ($req->internalReportRef !== null) {
					try {
						$this->ticketService->addInternalSystemNote(
							(int)$ticket->getId(),
							'Defect report ' . $req->internalReportRef . ' received via external form'
							. ($req->formSlug !== '' ? ' "' . $req->formSlug . '"' : '') . '.',
							'DefectCheck',
							self::ACTOR
						);
					} catch (\Throwable $e) {
						// The ticket is real; a missing provenance note must
						// not turn success into a retry that mints a duplicate.
						$this->logger->warning('DefectReportWriteFacade provenance note failed: ' . get_class($e));
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
			$this->logger->error('DefectReportWriteFacade failed: ' . get_class($e) . ' @ ' . $e->getFile() . ':' . $e->getLine());
			return FacadeResult::failure('internal_error', 'Ticket creation failed');
		}

		if (!isset($payload['ticketId'], $payload['ticketNumber'])) {
			$this->logger->error('DefectReportWriteFacade: malformed idempotency payload');
			return FacadeResult::failure('internal_error', 'Ticket creation failed');
		}

		// Deferred created-mail for the guest-actor path (see notifyCustomer
		// comment above). The anonymous path never reaches here with a real
		// address — the .invalid placeholder suppresses it upstream.
		if ($identity !== null && $req->reporterEmail !== null) {
			try {
				$fresh = $this->ticketService->getActiveTicket((int)$payload['ticketId']);
				$this->notifier->ticketCreated($fresh, null);
			} catch (\Throwable $e) {
				$this->logger->warning('DefectReportWriteFacade deferred notify failed: ' . get_class($e));
			}
		}

		return FacadeResult::success([
			'ticketId' => (int)$payload['ticketId'],
			'ticketNumber' => (string)$payload['ticketNumber'],
			'guestIdentityId' => $identity !== null ? (int)$identity->getId() : null,
		]);
	}

	/**
	 * Post-submit "want to track this?" link (US-002): resolve/create the
	 * guest identity, bind it to an existing ticket, trigger the standard
	 * portal invite mail. Idempotent per (ticketId, email) via the caller's
	 * idempotency key — repeated calls converge on the cached result and
	 * never resend the invite.
	 */
	public function linkReporter(string $idempotencyKey, int $ticketId, string $email, ?string $displayName): FacadeResult
	{
		$email = strtolower(trim($email));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return FacadeResult::failure('validation_failed', 'Invalid email address', ['fields' => ['email']]);
		}
		if ($ticketId <= 0) {
			return FacadeResult::failure('not_found', 'Ticket not found');
		}
		if ($displayName !== null && mb_strlen($displayName) > self::MAX_NAME_LEN) {
			return FacadeResult::failure('validation_failed', 'Display name too long', ['fields' => ['displayName']]);
		}

		try {
			$ticket = $this->ticketService->getActiveTicket($ticketId);
		} catch (\Throwable) {
			return FacadeResult::failure('not_found', 'Ticket not found');
		}
		$ticketId = (int)$ticket->getId();

		$existing = strtolower(trim((string)$ticket->getCustomerEmail()));
		$isPlaceholder = $existing === '' || str_ends_with($existing, '.invalid');
		if (!$isPlaceholder && $existing !== $email) {
			// A different real contact already claimed this ticket — never
			// reassign another reporter's tracking silently.
			return FacadeResult::failure('conflict', 'Ticket already linked to a different contact');
		}

		try {
			$payload = $this->runIdempotent(self::LINK_IDEM_SCOPE, $idempotencyKey !== '' ? $idempotencyKey : null, function () use ($ticketId, $email, $displayName, $existing, $isPlaceholder): array {
				[$identity, $reused] = $this->identities->findOrCreateWithReuse(
					$email,
					$displayName ?? $email,
					self::ACTOR
				);
				if ($identity->getStatus() !== GuestIdentityService::STATUS_ACTIVE) {
					return ['identityId' => 0, 'linked' => false, 'inviteSent' => false, 'inactive' => true];
				}

				$alreadyLinked = !$isPlaceholder && $existing === $email;
				if (!$alreadyLinked) {
					$this->ticketService->updateTicket($ticketId, [
						'customer_email' => $email,
						'customer_name' => trim((string)($displayName ?? '')) !== ''
							? $displayName
							: (string)$identity->getDisplayName(),
					]);
				}

				// The invite is the tracking link itself — send it even when
				// the ticket already carried this email (contact-at-submit),
				// otherwise the opt-in would be a dead end. Idempotency on the
				// caller key makes repeats converge without resending.
				$inviteSent = $this->invites->sendInvite((int)$identity->getId());

				return [
					'identityId' => (int)$identity->getId(),
					'linked' => true,
					'inviteSent' => $inviteSent,
					'alreadyLinked' => $alreadyLinked,
					'identityReused' => $reused,
				];
			});
		} catch (CompanionValidationException $e) {
			return FacadeResult::failure('validation_failed', 'Invalid idempotency key', ['fields' => ['idempotencyKey']]);
		} catch (\Throwable $e) {
			$this->logger->error('DefectReportWriteFacade linkReporter failed: ' . get_class($e) . ' @ ' . $e->getFile() . ':' . $e->getLine());
			return FacadeResult::failure('internal_error', 'Reporter linking failed');
		}

		if (($payload['inactive'] ?? false) === true) {
			return FacadeResult::failure('permission_denied', 'Reporter identity is not active');
		}
		if (!isset($payload['identityId'])) {
			$this->logger->error('DefectReportWriteFacade linkReporter: malformed payload');
			return FacadeResult::failure('internal_error', 'Reporter linking failed');
		}

		return FacadeResult::success([
			'guestIdentityId' => (int)$payload['identityId'],
			'linked' => (bool)($payload['linked'] ?? false),
			'inviteSent' => (bool)($payload['inviteSent'] ?? false),
			'alreadyLinked' => (bool)($payload['alreadyLinked'] ?? false),
		]);
	}

	private function validateRequest(DefectReportWriteRequest $req): ?FacadeResult
	{
		if ($req->formSlug === '' || mb_strlen($req->formSlug) > self::MAX_SLUG_LEN) {
			return FacadeResult::failure('validation_failed', 'formSlug is required', ['fields' => ['formSlug']]);
		}
		if ($req->title === '' || mb_strlen($req->title) > self::MAX_TITLE_LEN) {
			return FacadeResult::failure('validation_failed', 'Title must be 1–200 characters', ['fields' => ['title']]);
		}
		if (trim($req->descriptionHtml) === '' || mb_strlen($req->descriptionHtml) > self::MAX_DESCRIPTION_LEN) {
			return FacadeResult::failure('validation_failed', 'Description must be 1–20000 characters', ['fields' => ['descriptionHtml']]);
		}
		$categoryOk = $this->ticketCategories !== null
			? $this->ticketCategories->isEnabledKey($req->categoryKey)
			: in_array($req->categoryKey, \OCA\Ticketcheck\Db\Ticket::getCategories(), true);
		if (!$categoryOk) {
			return FacadeResult::failure('validation_failed', 'Unknown category', ['fields' => ['categoryKey']]);
		}
		if ($req->reporterEmail !== null && !filter_var($req->reporterEmail, FILTER_VALIDATE_EMAIL)) {
			return FacadeResult::failure('validation_failed', 'Invalid email address', ['fields' => ['reporterEmail']]);
		}
		if ($req->reporterDisplayName !== null && mb_strlen($req->reporterDisplayName) > self::MAX_NAME_LEN) {
			return FacadeResult::failure('validation_failed', 'Display name too long', ['fields' => ['reporterDisplayName']]);
		}
		if ($req->reporterPhone !== null && mb_strlen($req->reporterPhone) > self::MAX_PHONE_LEN) {
			return FacadeResult::failure('validation_failed', 'Phone too long', ['fields' => ['reporterPhone']]);
		}
		if (count($req->stagedFiles) > self::MAX_ATTACHMENTS) {
			return FacadeResult::failure('validation_failed', 'Too many attachments', ['fields' => ['attachments']]);
		}
		foreach ($req->stagedFiles as $file) {
			$tmp = (string)($file['tmpPath'] ?? '');
			$name = trim((string)($file['originalName'] ?? ''));
			$size = (int)($file['size'] ?? 0);
			if ($tmp === '' || $name === '' || $size <= 0 || $size > self::MAX_ATTACHMENT_BYTES) {
				return FacadeResult::failure('validation_failed', 'Invalid attachment descriptor', ['fields' => ['attachments']]);
			}
			if (!is_file($tmp) || filesize($tmp) !== $size) {
				return FacadeResult::failure('validation_failed', 'Staged file missing or size mismatch', ['fields' => ['attachments']]);
			}
		}
		return null;
	}

	/**
	 * @param list<array{tmpPath:string,originalName:string,size:int,mimeType:string}> $files
	 */
	private function ingestStagedFiles(int $ticketId, array $files): void
	{
		foreach ($files as $file) {
			$this->ticketService->addAttachmentFromEmail(
				$ticketId,
				(string)$file['tmpPath'],
				(string)$file['originalName'],
				(int)$file['size'],
				(string)$file['mimeType'],
				null,
				self::ACTOR
			);
		}
	}

	/**
	 * Converge concurrent first-attempts on the winner's stored row — same
	 * doctrine as the escalation facade (CompanionConflictException retry
	 * loop, five attempts ≈ 375 ms, far above a ticket insert).
	 *
	 * @param callable():array<string,mixed> $operation
	 * @return array<string,mixed>
	 */
	private function runIdempotent(string $scope, ?string $key, callable $operation): array
	{
		$attempts = 0;
		while (true) {
			try {
				return $this->idempotency->run(self::ACTOR, $scope, $key, $operation);
			} catch (CompanionConflictException $e) {
				if (++$attempts >= 5) {
					throw $e;
				}
				usleep(75_000);
			}
		}
	}
}
