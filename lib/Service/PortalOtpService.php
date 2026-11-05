<?php

declare(strict_types=1);

/**
 * Passwordless portal OTP (spec §9.4, MH-T01/T02).
 * 6 digits, random_int, SHA-256 at rest, configurable TTL, ≤5 attempts,
 * single active code per e-mail+purpose, single use, uniform verify result.
 * Ported from BookingCheck OtpService @31c2669e70 (COPIED-SOURCES.md).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\PortalOtp;
use OCA\Ticketcheck\Db\PortalOtpMapper;
use OCP\IDBConnection;

class PortalOtpService
{
    public const MAX_ATTEMPTS = 5;
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_PAIR = 'pair';

    public function __construct(
        private readonly PortalOtpMapper $otps,
        private readonly TokenService $tokens,
        private readonly GuestAuthConfig $authConfig,
        private readonly IDBConnection $db,
    ) {
    }

    /**
     * Issue a fresh code + magic link. Supersedes any prior active row.
     * Returns [code, magicToken, otpId] — plaintext goes to the mail only.
     */
    public function issue(string $email, ?int $identityId, string $ip, string $purpose = self::PURPOSE_LOGIN): array
    {
        $email = strtolower(trim($email));
        $code = $this->tokens->mintOtp();
        [$magicToken, $magicHash] = $this->tokens->mint();
        $ttl = $this->authConfig->otpTtlMinutes();
        $now = new \DateTime();

        $this->db->beginTransaction();
        try {
            $this->otps->supersedeActive($email, $purpose);
            $o = new PortalOtp();
            $o->setEmail($email);
            $o->setIdentityId($identityId);
            $o->setPurpose($purpose);
            $o->setCodeHash($this->tokens->hash($code));
            $o->setMagicHash($magicHash);
            $o->setAttempts(0);
            $o->setCreatedAt($now);
            $o->setExpiresAt((clone $now)->modify('+' . $ttl . ' minutes'));
            $o->setIpHash(hash('sha256', $ip));
            $o = $this->otps->insert($o);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return [$code, $magicToken, (int)$o->getId()];
    }

    /**
     * Verify a presented code. Fenced counter first, consume on success.
     * Returns the identity_id bound at issue time (null = unknown-send row
     * which never verifies), or null on uniform failure.
     */
    public function verify(string $email, string $code, string $purpose = self::PURPOSE_LOGIN): ?int
    {
        $row = $this->otps->findActive(strtolower(trim($email)), $purpose);
        if ($row === null || (int)$row->getAttempts() >= self::MAX_ATTEMPTS) {
            return null;
        }
        // Count the attempt BEFORE comparing — SQL-fenced so the cap is
        // exact under a parallel burst (spec US-T01 AC-5).
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_portal_otp')
            ->set('attempts', $qb->createFunction('attempts + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row->getId())))
            ->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter(self::MAX_ATTEMPTS)))
            ->andWhere($qb->expr()->isNull('used_at'));
        if ($qb->executeStatement() === 0) {
            return null;
        }
        if (!$this->tokens->verify($code, (string)$row->getCodeHash())) {
            return null;
        }
        return $this->consume((int)$row->getId())
            ? ($row->getIdentityId() !== null ? (int)$row->getIdentityId() : null)
            : null;
    }

    /** Magic-link consume: single use, hashed lookup → identity_id or null. */
    public function consumeMagic(string $token): ?int
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $row = $this->otps->findByMagicHash($this->tokens->hash($token));
        if ($row === null) {
            return null;
        }
        return $this->consume((int)$row->getId())
            ? ($row->getIdentityId() !== null ? (int)$row->getIdentityId() : null)
            : null;
    }

    private function consume(int $id): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_portal_otp')
            ->set('used_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->isNull('used_at'));
        return $qb->executeStatement() === 1;
    }
}
