<?php

declare(strict_types=1);

/**
 * One-time heal for the pre-provenance-flag guest pin cohort (issue #5).
 *
 * Released <=2.6.0 code pinned core.defaultapp='ticketcheck' and
 * firstrunwizard.show='999.0.0' at every helpdesk_customers member login
 * without stamping any provenance. The runtime self-heal
 * (GuestUserLoginListener::clearGuestPins) removes defaultapp=ticketcheck
 * on any non-member on its own — same policy as this step — but only when
 * the user actually logs in again; neither key has a user/admin UI to
 * revert it, so dormant accounts still need this pass.
 *
 * This repair enumerates exactly the uids carrying the app's pin values:
 *   - non-members: the pins are deleted outright (the documented exception
 *     to strict provenance — the defaultapp value points into this app and
 *     '999.0.0' is our firstrunwizard sentinel; an admin who occ-pinned a
 *     non-member to the portal loses that pin, and must re-set it if they
 *     re-pin — this step runs on every app update).
 *   - current members missing the flag: ticketcheck.portal_pins='1' is
 *     stamped so the runtime gate owns their app-written pins when they
 *     later leave the group.
 * files.quota is untouched here: only app-created accounts (guest_created_at
 * marker) ever carry an app-written quota pin, and the runtime self-heal
 * already clears those at the next login.
 *
 * Inlined wire values only — upgrade-path code must not reference constants
 * added in the same release (repair-step-DI skill §7).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Repair;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

class UnpinLegacyGuestPins implements IRepairStep
{
    public function __construct(
        private readonly IConfig $config,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'Unpin legacy TicketCheck guest portal pins';
    }

    public function run(IOutput $output): void
    {
        // The member lookup is best-effort: if the group backend throws,
        // treat every pinned uid as a non-member. Current members re-pin
        // themselves at their next login anyway (with the provenance flag),
        // so a transient unpin is harmless — while aborting here would
        // strand the entire legacy cohort this step exists to heal.
        try {
            $members = $this->guestMemberUids();
        } catch (\Throwable $e) {
            $members = [];
            $this->logger->warning('UnpinLegacyGuestPins: helpdesk_customers member lookup failed, unpinning all pinned users', [
                'exception' => $e,
            ]);
        }
        $healed = 0;
        $flagged = 0;
        $failed = 0;

        try {
            $pinned = array_merge(
                $this->config->getUsersForUserValue('core', 'defaultapp', 'ticketcheck'),
                $this->config->getUsersForUserValue('firstrunwizard', 'show', '999.0.0'),
            );
        } catch (\Throwable $e) {
            // Without the pin carriers there is nothing safe to heal — a
            // throw here must not break the upgrade (Repair only swallows
            // \Exception per step, and later steps in the list would be
            // skipped if this one escaped).
            $this->logger->error('UnpinLegacyGuestPins: pin enumeration failed', [
                'exception' => $e,
            ]);
            $output->warning('Ticketcheck: could not enumerate legacy guest portal pins — see log.');
            return;
        }

        foreach (array_unique($pinned) as $uid) {
            try {
                if (isset($members[$uid])) {
                    if ($this->config->getUserValue($uid, 'ticketcheck', 'portal_pins', '') !== '1') {
                        $this->config->setUserValue($uid, 'ticketcheck', 'portal_pins', '1');
                        $flagged++;
                    }
                    continue;
                }
                if ($this->config->getUserValue($uid, 'core', 'defaultapp') === 'ticketcheck') {
                    $this->config->deleteUserValue($uid, 'core', 'defaultapp');
                    $healed++;
                }
                if ($this->config->getUserValue($uid, 'firstrunwizard', 'show') === '999.0.0') {
                    $this->config->deleteUserValue($uid, 'firstrunwizard', 'show');
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->error('UnpinLegacyGuestPins failed for user', [
                    'user_id' => $uid,
                    'exception' => $e,
                ]);
            }
        }

        if ($healed === 0 && $flagged === 0 && $failed === 0) {
            $output->info('Ticketcheck: no legacy guest portal pins found.');
            return;
        }
        $output->info(sprintf(
            'Ticketcheck: unpinned legacy guest portal pins for %d user(s), stamped provenance for %d current member(s)%s.',
            $healed,
            $flagged,
            $failed > 0 ? sprintf(' (%d failed — see log)', $failed) : ''
        ));
    }

    /** @return array<string, true> uid => true for current helpdesk_customers members */
    private function guestMemberUids(): array
    {
        $group = $this->groupManager->get('helpdesk_customers');
        if ($group === null) {
            return [];
        }
        $uids = [];
        foreach ($group->getUsers() as $user) {
            $uids[$user->getUID()] = true;
        }
        return $uids;
    }
}
