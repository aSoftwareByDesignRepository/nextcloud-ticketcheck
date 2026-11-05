<?php

declare(strict_types=1);

/**
 * Guest auth retention (spec §7): OTP rows 24 h, dead sessions 30 d,
 * sent outbox 30 d / failed 90 d, audit rows 3 y, dead devices 30 d.
 * miglog rows are kept for the life of the install.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Db\GuestAuditMapper;
use OCA\Ticketcheck\Db\GuestDeviceMapper;
use OCA\Ticketcheck\Db\MailOutboxMapper;
use OCA\Ticketcheck\Db\PortalOtpMapper;
use OCA\Ticketcheck\Db\PortalSessionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class PortalRetentionJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private readonly PortalOtpMapper $otps,
        private readonly PortalSessionMapper $sessions,
        private readonly MailOutboxMapper $outbox,
        private readonly GuestDeviceMapper $devices,
        private readonly GuestAuditMapper $audit,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }

    protected function run($argument): void
    {
        try {
            $this->otps->deleteOlderThan(new \DateTime('-24 hours'));
        } catch (\Throwable) {
        }
        try {
            $this->sessions->deleteDeadOlderThan(new \DateTime('-30 days'));
        } catch (\Throwable) {
        }
        try {
            $this->devices->deleteDeadOlderThan(new \DateTime('-30 days'));
        } catch (\Throwable) {
        }
        try {
            $this->outbox->deleteSentOlderThan(new \DateTime('-30 days'), 'sent');
            $this->outbox->deleteSentOlderThan(new \DateTime('-90 days'), 'failed');
        } catch (\Throwable) {
        }
        try {
            $this->audit->deleteOlderThan(new \DateTime('-3 years'));
        } catch (\Throwable) {
        }
    }
}
