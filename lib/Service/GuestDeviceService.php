<?php

declare(strict_types=1);

/**
 * Companion client device tokens (spec §10.7). Pairing consumes a `pair`
 * OTP bound to the identity; the device then presents `X-TicketCheck-Device`.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\GuestDevice;
use OCA\Ticketcheck\Db\GuestDeviceMapper;
use OCP\IDBConnection;

class GuestDeviceService
{
    public const HEADER = 'x-ticketcheck-device';
    private const DAYS = 365;

    public function __construct(
        private readonly GuestDeviceMapper $devices,
        private readonly TokenService $tokens,
        private readonly IDBConnection $db,
    ) {
    }

    /** @return array{0:string,1:GuestDevice} plaintext device token + row */
    public function pair(int $identityId, string $label): array
    {
        [$token, $hash] = $this->tokens->mint();
        $now = new \DateTime();
        $d = new GuestDevice();
        $d->setIdentityId($identityId);
        $d->setTokenHash($hash);
        $d->setLabel(mb_substr(trim($label), 0, 190));
        $d->setCreatedAt($now);
        $d->setLastSeenAt(clone $now);
        $d->setExpiresAt((clone $now)->modify('+' . self::DAYS . ' days'));
        $this->devices->insert($d);
        return [$token, $d];
    }

    /** Resolve a presented device token → live row or null; slides last_seen. */
    public function resolve(string $token): ?GuestDevice
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $row = $this->devices->findByTokenHash($this->tokens->hash($token));
        if ($row === null) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_guest_device')
            ->set('last_seen_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row->getId())));
        $qb->executeStatement();
        return $row;
    }

    public function revokeById(int $id, int $identityId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_guest_device')
            ->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
            ->andWhere($qb->expr()->isNull('revoked_at'));
        return $qb->executeStatement() === 1;
    }
}
