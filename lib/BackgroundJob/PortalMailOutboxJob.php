<?php

declare(strict_types=1);

/**
 * Drains the guest mail outbox on a short interval (spec D-T08).
 * OTP mails additionally drain via the post-response hook in
 * PortalAuthController — this job is the reliable fallback.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Service\GuestMailOutboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class PortalMailOutboxJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private readonly GuestMailOutboxService $outbox,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
    }

    protected function run($argument): void
    {
        $this->outbox->drainDue(100);
    }
}
