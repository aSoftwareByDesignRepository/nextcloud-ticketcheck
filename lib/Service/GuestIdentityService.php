<?php

declare(strict_types=1);

/**
 * Guest identity lifecycle (spec §9.3, US-T03, US-T07).
 * active → disabled → erased (erase terminal; disable→active allowed).
 * Erase anonymizes PII, drops sessions/OTP/alias/access, keeps history.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\GuestAliasMapper;
use OCA\Ticketcheck\Db\GuestAuditMapper;
use OCA\Ticketcheck\Db\GuestDeviceMapper;
use OCA\Ticketcheck\Db\GuestIdentity;
use OCA\Ticketcheck\Db\GuestIdentityMapper;
use OCA\Ticketcheck\Db\GuestPrefMapper;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Db\PortalOtpMapper;
use OCA\Ticketcheck\Db\PortalSessionMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

class GuestIdentityService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ERASED = 'erased';

    public function __construct(
        private readonly GuestIdentityMapper $identities,
        private readonly GuestAliasMapper $aliases,
        private readonly GuestPrefMapper $prefs,
        private readonly GuestProjectAccessMapper $access,
        private readonly PortalSessionMapper $sessions,
        private readonly PortalOtpMapper $otps,
        private readonly GuestDeviceMapper $devices,
        private readonly GuestAuditMapper $audit,
        private readonly IDBConnection $db,
    ) {
    }

    /** Find-or-create by lowered e-mail (unique key; dup race → re-read). */
    public function findOrCreate(string $email, string $displayName, string $createdBy, ?string $locale = null): GuestIdentity
    {
        $email = strtolower(trim($email));
        $existing = $this->identities->findByEmail($email);
        if ($existing !== null) {
            return $existing;
        }
        $i = new GuestIdentity();
        $i->setEmail($email);
        $i->setDisplayName(mb_substr(trim($displayName), 0, 190) !== '' ? mb_substr(trim($displayName), 0, 190) : $email);
        $i->setLocale($locale !== null && trim($locale) !== '' ? trim($locale) : null);
        $i->setStatus(self::STATUS_ACTIVE);
        $i->setCreatedAt(new \DateTime());
        $i->setCreatedBy($createdBy !== '' ? $createdBy : 'system');
        try {
            return $this->identities->insert($i);
        } catch (DbException $e) {
            if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            $again = $this->identities->findByEmail($email);
            if ($again !== null) {
                return $again;
            }
            throw $e;
        }
    }

    public function update(int $id, string $displayName, ?string $email = null, ?string $locale = null): GuestIdentity
    {
        $i = $this->requireIdentity($id);
        if ($i->getStatus() === self::STATUS_ERASED) {
            throw new \InvalidArgumentException('erased identity is immutable');
        }
        $i->setDisplayName(mb_substr(trim($displayName), 0, 190));
        if ($email !== null) {
            $new = strtolower(trim($email));
            if (!filter_var($new, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('invalid_email');
            }
            if ($new !== $i->getEmail()) {
                $other = $this->identities->findByEmail($new);
                if ($other !== null && (int)$other->getId() !== $id) {
                    throw new \InvalidArgumentException('email_in_use');
                }
                $i->setEmail($new);
            }
        }
        if ($locale !== null) {
            $i->setLocale(trim($locale) !== '' ? trim($locale) : null);
        }
        $i->setUpdatedAt(new \DateTime());
        return $this->identities->update($i);
    }

    public function setStatus(int $id, string $status, string $actor): GuestIdentity
    {
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('invalid status transition');
        }
        $i = $this->requireIdentity($id);
        if ($i->getStatus() === self::STATUS_ERASED) {
            throw new \InvalidArgumentException('erased identity is immutable');
        }
        $i->setStatus($status);
        $i->setUpdatedAt(new \DateTime());
        $i = $this->identities->update($i);
        if ($status === self::STATUS_DISABLED) {
            $this->sessions->revokeByIdentity($id);
            $this->devices->revokeByIdentity($id);
        }
        $this->audit->log($actor, 'identity_' . $status, 'portal:' . $id);
        return $i;
    }

    /**
     * GDPR erase (US-T07 AC-2): anonymize PII, drop sessions/OTP/alias/
     * prefs/access/device rows. Ticket/comment history keeps the actor
     * string — it resolves to the anonymized display name afterwards.
     */
    public function erase(int $id, string $actor): void
    {
        $i = $this->requireIdentity($id);
        $email = (string)$i->getEmail();
        $this->db->beginTransaction();
        try {
            $this->sessions->deleteByIdentityId($id);
            $this->otps->deleteByIdentityId($id);
            $this->aliases->deleteByIdentityId($id);
            $this->prefs->deleteByIdentityId($id);
            $this->devices->deleteByIdentityId($id);
            $this->access->deleteByUser(PortalActorResolver::actorForIdentity($id));
            $i->setEmail('deleted+' . $id . '@invalid');
            $i->setDisplayName('Deleted contact');
            $i->setLocale(null);
            $i->setStatus(self::STATUS_ERASED);
            $i->setUpdatedAt(new \DateTime());
            $this->identities->update($i);
            $this->audit->log($actor, 'identity_erased', 'portal:' . $id, ['email_hash' => hash('sha256', $email)]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function grantProjects(int $identityId, array $projectIds, ?int $customerId, string $createdBy): void
    {
        $actor = PortalActorResolver::actorForIdentity($identityId);
        foreach ($projectIds as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0 || $this->access->hasAccess($actor, $pid)) {
                continue;
            }
            $this->access->grantAccess($actor, $pid, $customerId, $createdBy);
            $this->audit->log($createdBy, 'membership_granted', $actor, ['project_id' => $pid]);
        }
    }

    public function revokeProject(int $identityId, int $projectId, string $actor): void
    {
        $this->access->revokeAccess(PortalActorResolver::actorForIdentity($identityId), $projectId);
        $this->audit->log($actor, 'membership_revoked', PortalActorResolver::actorForIdentity($identityId), ['project_id' => $projectId]);
    }

    /** @return list<GuestIdentity> admin listing (identities table) */
    public function listAll(): array
    {
        return $this->identities->findAll();
    }

    /** Identity-scoped boolean pref (gdpr ack, etc.) — yes/no vocabulary. */
    public function setPref(int $identityId, string $key, bool $on): void
    {
        $this->prefs->upsert($identityId, $key, $on ? 'yes' : 'no');
    }

    public function getPref(int $identityId, string $key): bool
    {
        return ($this->prefs->mapForIdentity($identityId)[$key] ?? 'no') === 'yes';
    }

    public function requireIdentity(int $id): GuestIdentity
    {
        $i = $this->findIdentity($id);
        if ($i === null) {
            throw new \InvalidArgumentException('identity_not_found');
        }
        return $i;
    }

    public function findIdentity(int $id): ?GuestIdentity
    {
        return $this->identities->find($id);
    }
}
