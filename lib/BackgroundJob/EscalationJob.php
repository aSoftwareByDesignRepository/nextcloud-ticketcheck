<?php

declare(strict_types=1);

/**
 * Escalation background job for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Service\EscalationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Background job to run escalation rules hourly
 */
class EscalationJob extends TimedJob
{
    private const JOB_LOCK_KEY = 'ticketcheck/escalation_job';

    private EscalationService $escalationService;
    private LoggerInterface $logger;
    private ILockingProvider $locking;

    public function __construct(
        ITimeFactory $time,
        EscalationService $escalationService,
        LoggerInterface $logger,
        ILockingProvider $locking,
    ) {
        parent::__construct($time);
        $this->escalationService = $escalationService;
        $this->logger = $logger;
        $this->locking = $locking;

        $this->setInterval(3600);
    }

    /**
     * Run escalation rules
     */
    protected function run($argument): void
    {
        $this->logger->info('Running Escalation Job');
        try {
            try {
                $this->locking->acquireLock(self::JOB_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE, 'TicketCheck escalation');
            } catch (LockedException $e) {
                $this->logger->info('Escalation job already running in another worker, skipping');
                return;
            }

            try {
                $this->escalationService->runEscalation();
                $this->logger->info('Escalation Job completed');
            } finally {
                try {
                    $this->locking->releaseLock(self::JOB_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to release escalation job lock', ['exception' => $e]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Escalation Job failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
