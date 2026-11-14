<?php

declare(strict_types=1);

/**
 * Guest User Login Listener
 * Redirects guest users to portal immediately after login
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IConfig;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<PostLoginEvent>
 */
class GuestUserLoginListener implements IEventListener
{
    private const GROUP_GUESTS = 'helpdesk_customers';
    private const DEFAULT_APP = 'ticketcheck';
    private const GUEST_QUOTA = '0 B';
    private const GUEST_MARKER_KEY = 'guest_created_at';
    private const PORTAL_PINS_KEY = 'portal_pins';
    private const FIRSTRUN_SUPPRESS_VERSION = '999.0.0';

    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof PostLoginEvent)) {
            return;
        }

        $user = $event->getUser();
        $userId = $user->getUID();

        // Any failure here must never turn the login POST into a 500 — the
        // best-effort pin is a convenience, not a gate; it retries next login.
        try {
            if ($this->groupManager->isInGroup($userId, self::GROUP_GUESTS)) {
                // Provenance flag FIRST: clearGuestPins() may only unpin
                // values the app wrote itself, and stored-value equality
                // cannot prove authorship. Writing it before the pins
                // guarantees a mid-sequence write failure can never strand
                // an unmarked pin that no cleanup path will ever touch.
                $this->config->setUserValue($userId, 'ticketcheck', self::PORTAL_PINS_KEY, '1');

                // Set default app for this user to helpdesk portal
                // This ensures they're redirected after login completes
                $this->config->setUserValue($userId, 'core', 'defaultapp', self::DEFAULT_APP);

                // Disable firstrunwizard for guest users to prevent welcome modal
                $this->config->setUserValue($userId, 'firstrunwizard', 'show', self::FIRSTRUN_SUPPRESS_VERSION);
                return;
            }

            // Self-heal: users who left helpdesk_customers must not stay pinned
            // to TicketCheck — the per-user defaultapp value has no self-service
            // or admin UI to revert it (issue #5). PostLoginEvent fires before
            // the post-login redirect resolves, so clearing here already fixes
            // this login's landing page. The guest zero-quota isolation write
            // gets the same treatment — but only on accounts the app provably
            // created or pinned; admin-set values are never touched.
            self::clearGuestPins($this->config, $userId);
        } catch (\Throwable $e) {
            $this->logger->error('GuestUserLoginListener failed', [
                'user_id' => $userId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Undo the guest isolation pins — but only state the app provably wrote.
     * Stored-value equality is not provenance: '0 B' is a legitimate admin
     * quota (upload-disabled accounts) and defaultapp=ticketcheck can be an
     * admin's deliberate choice. Two markers establish authorship instead:
     *  - ticketcheck.guest_created_at — stamped in the same write block as the
     *    '0 B' quota + defaultapp pins at guest creation; its presence proves
     *    the account (and therefore its quota pin) came from this app.
     *  - ticketcheck.portal_pins — stamped alongside the defaultapp +
     *    firstrunwizard pins at every member login.
     * Provenance is consumed on undo: once the pins are cleared the account is
     * fully the admin's again, so a '0 B' quota set on it afterwards stays
     * put. Accounts the app never created keep every user value untouched —
     * in particular files.quota is never deleted for them.
     * Pins written before these markers existed (pre-flag member logins) were
     * meant to be healed once by the UnpinLegacyGuestPins post-migration
     * repair step — but that step is one-shot and silent failures leave no
     * runtime fallback (GH #4). The '999.0.0' firstrunwizard value doubles as
     * provenance there: it is our sentinel and can only exist where the app
     * wrote the pair, so it un-pins defaultapp for non-members on its own.
     */
    public static function clearGuestPins(IConfig $config, string $userId): void
    {
        if ($userId === '') {
            return;
        }

        $appCreated = $config->getUserValue($userId, 'ticketcheck', self::GUEST_MARKER_KEY, '') !== '';
        $hasPinsFlag = $config->getUserValue($userId, 'ticketcheck', self::PORTAL_PINS_KEY, '') === '1';
        $pinsOurs = $appCreated || $hasPinsFlag;

        // Sentinel-pair provenance for the pre-flag cohort: '999.0.0' is this
        // app's magic value — nothing else ever writes it — so a non-member
        // carrying it proves the <=2.6.0 member-login code wrote the whole
        // defaultapp+firstrunwizard pair, even when the provenance markers
        // were never stamped or were lost (GH #4: the one-shot upgrade repair
        // is the only other healer for marker-less pins, and any instance it
        // misses stays trapped forever). A lone defaultapp=ticketcheck with
        // neither sentinel nor markers is still preserved: it can be an
        // admin's deliberate pin for a staff user.
        $wizardPinned = $config->getUserValue($userId, 'firstrunwizard', 'show') === self::FIRSTRUN_SUPPRESS_VERSION;

        if (($pinsOurs || $wizardPinned) && $config->getUserValue($userId, 'core', 'defaultapp') === self::DEFAULT_APP) {
            $config->deleteUserValue($userId, 'core', 'defaultapp');
        }
        if ($wizardPinned) {
            $config->deleteUserValue($userId, 'firstrunwizard', 'show');
        }
        // The quota pin is strictly marker-gated: only an app-created
        // account's '0 B' is guaranteed to be an app write.
        if ($appCreated && $config->getUserValue($userId, 'files', 'quota') === self::GUEST_QUOTA) {
            $config->deleteUserValue($userId, 'files', 'quota');
        }
        if ($hasPinsFlag) {
            $config->deleteUserValue($userId, 'ticketcheck', self::PORTAL_PINS_KEY);
        }
        if ($appCreated) {
            // Consume the marker: the app's pin state is fully undone, so any
            // '0 B' or pin an admin sets on this account later is theirs.
            $config->deleteUserValue($userId, 'ticketcheck', self::GUEST_MARKER_KEY);
        }
    }
}
