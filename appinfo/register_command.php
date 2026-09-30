<?php

declare(strict_types=1);

use OCA\Ticketcheck\Command\SeedPrototypeFundBacklogCommand;
use OCA\Ticketcheck\Command\SyncRoleAccessCommand;
use OCA\Ticketcheck\Db\HelpdeskCustomerMapper;
use OCA\Ticketcheck\Db\HelpdeskProjectMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\RoleAccessSyncService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

try {
    /** @var \Symfony\Component\Console\Application $application */
    $application->add(new SyncRoleAccessCommand(
        new RoleAccessSyncService(
            Server::get(IDBConnection::class),
            Server::get(IGroupManager::class),
            Server::get(IUserManager::class),
            Server::get(LoggerInterface::class)
        )
    ));
    $application->add(new SeedPrototypeFundBacklogCommand(
        Server::get(IDBConnection::class),
        Server::get(HelpdeskProjectMapper::class),
        Server::get(HelpdeskCustomerMapper::class),
        Server::get(TicketMapper::class),
        Server::get(IUserManager::class),
        Server::get(LoggerInterface::class),
    ));
} catch (\Throwable $e) {
    // Keep occ startup resilient if service wiring fails.
}

