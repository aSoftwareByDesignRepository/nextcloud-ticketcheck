<?php

declare(strict_types=1);

/**
 * Staff-triggered guest portal invite (NH-01).
 *
 * Invite mail = OTP + magic link, never a credential (US-T03 AC-2). Shared by
 * GuestIdentityController (identity admin) and TicketController (ticket-view
 * CTA for account-less customer_email) so both paths emit the identical mail.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\IURLGenerator;

class GuestInviteService
{
    public function __construct(
        private readonly GuestIdentityService $identities,
        private readonly PortalOtpService $otp,
        private readonly GuestMailOutboxService $outbox,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }

    /** Returns false when the identity is missing/inactive or enqueue fails. */
    public function sendInvite(int $identityId): bool
    {
        try {
            $identity = $this->identities->requireIdentity($identityId);
        } catch (\InvalidArgumentException) {
            return false;
        }
        if ($identity->getStatus() !== 'active') {
            return false;
        }
        $email = (string)$identity->getEmail();
        [, $magic, $otpId] = $this->otp->issue($email, $identityId, 'staff-invite');
        return $this->outbox->enqueue('invite:' . $otpId, $email, GuestMailOutboxService::TPL_INVITE, [
            'display_name' => (string)$identity->getDisplayName(),
            'magic_url' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.portalAuth.magic', ['token' => $magic]),
            'locale' => (string)($identity->getLocale() ?? ''),
        ], capped: false) !== null;
    }
}
