<?php

declare(strict_types=1);

/**
 * Bearer-token mint/verify for the passwordless guest portal.
 * Only SHA-256 hashes are ever persisted (spec NN-T01).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

class TokenService
{
    /** @return array{0:string,1:string} [plaintext token, sha256 hash] */
    public function mint(int $bytes = 32): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(max(1, $bytes))), '+/', '-_'), '=');
        return [$token, $this->hash($token)];
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function verify(string $presentedToken, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($presentedToken));
    }

    /** Human-typable 6-digit OTP; random_int only. */
    public function mintOtp(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
