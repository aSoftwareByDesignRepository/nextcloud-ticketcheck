<?php

declare(strict_types=1);

/**
 * S5 upgrade gate (spec MH-T15, §9.1, AC-R4). When the guest S5 gate is
 * armed (`guest_s5_gate` app value — a deliberate admin action shipped ≥2
 * releases after S0), occ upgrade FAILS while un-migrated guests remain
 * that have neither an alias nor an `abandoned` miglog mark. nc_fallback
 * alone never satisfies the gate.
 *
 * Inlined wire values only — upgrade-path code must not reference constants
 * added in the same release (repair-step-DI skill §7).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Repair;

use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class EnforceGuestMigrationGate implements IRepairStep
{
    public function __construct(
        private readonly IDBConnection $db,
        private readonly IConfig $config,
        private readonly IGroupManager $groupManager,
    ) {
    }

    public function getName(): string
    {
        return 'TicketCheck guest migration gate (S5)';
    }

    public function run(IOutput $output): void
    {
        if ($this->config->getAppValue('ticketcheck', 'guest_s5_gate', 'no') !== 'yes') {
            return; // gate not armed — S5 has not been scheduled on this install
        }
        $blocking = $this->countBlockingGuests();
        if ($blocking > 0) {
            $output->warning(sprintf(
                'TicketCheck: %d guest account(s) are neither migrated nor marked abandoned. Migrate them via `occ ticketcheck:guests:migrate --run` or mark them abandoned in the migration wizard before upgrading.',
                $blocking
            ));
            throw new \RuntimeException(sprintf(
                'TicketCheck S5 gate: %d un-resolved guest account(s) block the upgrade.',
                $blocking
            ));
        }
    }

    private function countBlockingGuests(): int
    {
        $group = $this->groupManager->get('helpdesk_customers');
        if ($group === null) {
            return 0;
        }
        $aliased = $this->uidSet('helpdesk_guest_alias', 'legacy_uid');
        $abandoned = $this->abandonedUids();
        $blocking = 0;
        foreach ($group->getUsers() as $user) {
            $uid = $user->getUID();
            if (!in_array($uid, $aliased, true) && !in_array($uid, $abandoned, true)) {
                $blocking++;
            }
        }
        return $blocking;
    }

    /** @return list<string> */
    private function uidSet(string $table, string $column): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($column)->from($table)->groupBy($column);
            $result = $qb->executeQuery();
            $uids = [];
            while (($r = $result->fetch()) !== false) {
                $uids[] = (string)$r[$column];
            }
            $result->closeCursor();
            return $uids;
        } catch (\Throwable) {
            return []; // table not present yet → nothing migrated → count stays accurate
        }
    }

    /** @return list<string> */
    private function abandonedUids(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('legacy_uid')->from('helpdesk_guest_miglog')
                ->where($qb->expr()->eq('stage', $qb->createNamedParameter('abandoned')))
                ->groupBy('legacy_uid');
            $result = $qb->executeQuery();
            $uids = [];
            while (($r = $result->fetch()) !== false) {
                $uids[] = (string)$r['legacy_uid'];
            }
            $result->closeCursor();
            return $uids;
        } catch (\Throwable) {
            return [];
        }
    }
}
