<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * NC Notifications for companion-relevant events (assign + guest/public comments).
 * Never notifies helpdesk_customers.
 */
class CompanionNotificationService
{
	public function __construct(
		private readonly INotificationManager $notifications,
		private readonly IURLGenerator $urlGenerator,
		private readonly TicketWatcherMapper $watcherMapper,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly ?CompanionPushService $push = null,
		private readonly ?PermissionService $permissions = null,
	) {
	}

	public function notifyAssigned(Ticket $ticket, string $actorUid): void
	{
		$assignee = $ticket->getAssignedTo();
		if ($assignee === null || $assignee === '' || $assignee === $actorUid) {
			return;
		}
		$this->notifyUsers([$assignee], $ticket, 'ticket_assigned', [
			'actor' => $actorUid,
			'title' => $ticket->getTitle(),
			'number' => $ticket->getTicketNumber(),
		]);
	}

	public function notifyPublicComment(Ticket $ticket, string $actorUid): void
	{
		// Spec §6.4: fan-out only for guest/customer public comments — not agent replies.
		// Internal requester-scope uids count as customer-side authors (D-R14).
		if (!$this->groupManager->isInGroup($actorUid, PermissionService::GROUP_HELPDESK_CUSTOMERS)
			&& !($this->permissions?->isRequesterGroupMember($actorUid) ?? false)) {
			return;
		}
		$recipients = $this->staffRecipients($ticket, $actorUid);
		$this->notifyUsers($recipients, $ticket, 'ticket_public_comment', [
			'actor' => $actorUid,
			'title' => $ticket->getTitle(),
			'number' => $ticket->getTicketNumber(),
		]);
	}

	public function notifyStatusChanged(Ticket $ticket, string $actorUid, string $oldStatus, string $newStatus): void
	{
		if ($newStatus !== Ticket::STATUS_WAITING) {
			return;
		}
		$assignee = $ticket->getAssignedTo();
		if ($assignee === null || $assignee === '' || $assignee === $actorUid) {
			return;
		}
		$this->notifyUsers([$assignee], $ticket, 'ticket_waiting', [
			'actor' => $actorUid,
			'title' => $ticket->getTitle(),
			'number' => $ticket->getTicketNumber(),
			'old' => $oldStatus,
			'new' => $newStatus,
		]);
	}

	/**
	 * @param list<string> $uids
	 * @param array<string, string> $params
	 */
	private function notifyUsers(array $uids, Ticket $ticket, string $subject, array $params): void
	{
		$ncNotifyEnabled = $this->appManager->isEnabledForUser('notifications');
		$pushIntent = $this->pushIntentFor($subject);
		if (!$ncNotifyEnabled && ($pushIntent === null || $this->push === null)) {
			return;
		}
		$link = $ncNotifyEnabled
			? $this->urlGenerator->linkToRouteAbsolute('ticketcheck.ticket.show', ['id' => $ticket->getId()])
			: '';
		foreach (array_unique($uids) as $uid) {
			if ($uid === '' || $this->groupManager->isInGroup($uid, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
				continue;
			}
			if ($this->userManager->get($uid) === null) {
				continue;
			}
			if ($ncNotifyEnabled) {
				try {
					$n = $this->notifications->createNotification();
					$n->setApp(Application::APP_ID)
						->setUser($uid)
						->setObject('ticket', (string)$ticket->getId())
						->setSubject($subject, $params)
						->setDateTime(new \DateTime())
						->setLink($link);
					$this->notifications->notify($n);
				} catch (\Throwable $e) {
					$this->logger->warning('TicketCheck companion notification failed', [
						'subject' => $subject,
						'uid' => $uid,
						'ticket_id' => $ticket->getId(),
						'exception' => $e,
					]);
				}
			}
			if ($pushIntent !== null && $this->push !== null) {
				try {
					// Device push carries the id + number only — never the ticket
					// title or comment body (lock-screen data minimization).
					$this->push->enqueue($uid, $pushIntent, [
						'ticketId' => $ticket->getId(),
						'number' => $ticket->getTicketNumber(),
					]);
				} catch (\Throwable $e) {
					$this->logger->warning('TicketCheck companion push enqueue failed', [
						'intent' => $pushIntent,
						'uid' => $uid,
						'ticket_id' => $ticket->getId(),
						'exception' => $e,
					]);
				}
			}
		}
	}

	/**
	 * NC notification bell for requester-scope ticket events (spec NH-01).
	 * Recipients come from the single requester-scope authority (creator +
	 * restricted e-mail leg — D-R05/D-R15); no push — requesters hold no
	 * companion seat. The bell links to the PORTAL ticket view: the staff
	 * ticket route would deny them.
	 *
	 * @param array<string, string> $params
	 */
	public function notifyRequesterTicketEvent(Ticket $ticket, string $actorUid, string $subject, array $params = []): void
	{
		if ($this->permissions === null || !$this->appManager->isEnabledForUser('notifications')) {
			return;
		}
		$recipients = array_values(array_filter(
			$this->permissions->requesterRecipientUids($ticket),
			static fn (string $uid): bool => $uid !== '' && $uid !== $actorUid
		));
		if ($recipients === []) {
			return;
		}
		$link = $this->urlGenerator->linkToRouteAbsolute('ticketcheck.customerPortal.viewTicket', [
			'id' => $ticket->getId(),
		]);
		$params += [
			'actor' => $actorUid,
			'title' => $ticket->getTitle(),
			'number' => $ticket->getTicketNumber(),
		];
		foreach (array_unique($recipients) as $uid) {
			if ($this->userManager->get($uid) === null) {
				continue;
			}
			try {
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)
					->setUser($uid)
					->setObject('ticket', (string)$ticket->getId())
					->setSubject($subject, $params)
					->setDateTime(new \DateTime())
					->setLink($link);
				$this->notifications->notify($n);
			} catch (\Throwable $e) {
				$this->logger->warning('TicketCheck requester notification failed', [
					'subject' => $subject,
					'uid' => $uid,
					'ticket_id' => $ticket->getId(),
					'exception' => $e,
				]);
			}
		}
	}

	private function pushIntentFor(string $subject): ?string
	{
		return match ($subject) {
			'ticket_assigned' => CompanionPushService::INTENT_ASSIGNED,
			'ticket_public_comment' => CompanionPushService::INTENT_PUBLIC_COMMENT,
			'ticket_waiting' => CompanionPushService::INTENT_WAITING,
			default => null,
		};
	}

	/**
	 * @return list<string>
	 */
	private function staffRecipients(Ticket $ticket, string $actorUid): array
	{
		$uids = [];
		$assignee = $ticket->getAssignedTo();
		if (is_string($assignee) && $assignee !== '') {
			$uids[] = $assignee;
		}
		try {
			foreach ($this->watcherMapper->findByTicketId($ticket->getId()) as $watcher) {
				$wuid = method_exists($watcher, 'getUserId') ? (string)$watcher->getUserId() : '';
				if ($wuid !== '') {
					$uids[] = $wuid;
				}
			}
		} catch (\Throwable) {
			// watchers optional
		}
		$uids = array_values(array_filter(
			array_unique($uids),
			static fn (string $id): bool => $id !== $actorUid
		));
		return $uids;
	}
}
