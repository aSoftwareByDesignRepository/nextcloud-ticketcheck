<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Listener;

use OCA\Ticketcheck\Service\PermissionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/** @template-implements IEventListener<UserDeletedEvent> */
class UserDeletedListener implements IEventListener
{
	public function __construct(private PermissionService $permissions)
	{
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$this->permissions->purgeUser($event->getUser()->getUID());
	}
}
