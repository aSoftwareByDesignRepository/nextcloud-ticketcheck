<?php

declare(strict_types=1);

/**
 * One-time heal for the pre-provenance-flag guest pin cohort (issue #5).
 *
 * Released <=2.6.0 code pinned core.defaultapp='ticketcheck' and
 * firstrunwizard.show='999.0.0' at every helpdesk_customers member login
 * without stamping any provenance. The runtime self-heal
 * (GuestUserLoginListener::clearGuestPins) is deliberately provenance-
 * gated — it only unpins state the app provably wrote — which leaves
 * marker-less members who left the group before their first post-upgrade
 * login permanently trapped on the portal: neither key has a user/admin
 * UI to revert it.
 *
 * This repair enumerates exactly the uids carrying the app's pin values:
 *   - non-members: the pins are deleted outright (the once-only documented
 *     exception to strict provenance — the defaultapp value points into
 *     this app and '999.0.0' is our firstrunwizard sentinel; an admin who
 *     occ-pinned a non-member to the portal loses that pin once).
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
        $members = $this->guestMemberUids();
        $healed = 0;
        $flagged = 0;
        $failed = 0;

        $pinned = array_merge(
            $this->config->getUsersForUserValue('core', 'defaultapp', 'ticketcheck'),
            $this->config->getUsersForUserValue('firstrunwizard', 'show', '999.0.0'),
        );

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
