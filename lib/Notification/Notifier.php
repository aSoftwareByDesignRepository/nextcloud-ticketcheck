<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Notification;

use OCA\Ticketcheck\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10nFactory->get(Application::APP_ID)->t('TicketCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$number = (string)($params['number'] ?? '');
		$title = (string)($params['title'] ?? '');
		$link = $this->urlGenerator->linkToRouteAbsolute('ticketcheck.ticket.show', [
			'id' => $notification->getObjectId(),
		]);

		switch ($notification->getSubject()) {
			case 'ticket_assigned':
				$notification->setParsedSubject($l->t('Ticket assigned: %s', [$number !== '' ? $number : $title]));
				$notification->setParsedMessage($l->t('Open TicketCheck to work on this ticket.'));
				$notification->setLink($link);
				return $notification;
			case 'ticket_public_comment':
				$notification->setParsedSubject($l->t('New reply on %s', [$number !== '' ? $number : $title]));
				$notification->setParsedMessage($l->t('A public comment was added. Open TicketCheck to respond.'));
				$notification->setLink($link);
				return $notification;
			case 'ticket_waiting':
				$notification->setParsedSubject($l->t('Ticket waiting: %s', [$number !== '' ? $number : $title]));
				$notification->setParsedMessage($l->t('Status changed to waiting. Open TicketCheck to continue.'));
				$notification->setLink($link);
				return $notification;
			default:
				throw new UnknownNotificationException();
		}
	}
}
