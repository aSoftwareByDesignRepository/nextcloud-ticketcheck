<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Config\VendorPublicKey;
use OCA\Ticketcheck\License\Tkc2Codec;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Role + license reporting for companion bootstrap / middleware.
 *
 * Agents and helpdesk admins (and NC admins) may use companion.
 * Guests and dual-role guest∩agent are denied (guest-wins via PermissionService).
 */
class CompanionGateService
{
	public const COMPANION_MIN = 1;

	/**
	 * Honest capability map: list only what this server build actually serves.
	 * Clients hide UI for absent features (spec §6.5 / plan §2.4.7).
	 */
	public const FEATURES = [
		'inbox',
		'comments',
		'status',
		'assign',
		// `push` is appended dynamically when CompanionPushService::isFeatureEnabled()
		// (ops flag + Expo access token) — never advertise delivery that cannot run.
		'attachments',
		'filters',
		'sla',
		'watchers',
		'create',
		'bulk',
		'offlineRead',
		// G-KB (WP-113): read-only agent KB search + article view.
		'kb',
	];

	public function __construct(
		private readonly PermissionService $permissions,
		private readonly LicenseService $license,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
		private readonly ?IAppManager $appManager = null,
		private readonly ?CompanionPushService $push = null,
	) {
	}

	public function canAccessCompanion(string $uid): bool
	{
		return $this->permissions->canViewHelpdeskOverview($uid);
	}

	public function canAccessRequesterCompanion(string $uid): bool
	{
		try {
			return $uid !== '' && $this->userManager->get($uid) !== null
				&& !$this->groupManager->isInGroup($uid, PermissionService::GROUP_HELPDESK_CUSTOMERS)
				&& $this->permissions->isRequesterGroupMember($uid)
				&& $this->permissions->canUserAccessAppByUid($uid);
		} catch (\Throwable) {
			return false;
		}
	}

	public function resolveRole(string $uid): string
	{
		if (!$this->canAccessCompanion($uid)) {
			return $this->resolveDeniedVariant($uid);
		}
		if ($this->userManager->get($uid) === null) {
			return 'denied';
		}
		if ($this->groupManager->isAdmin($uid)
			|| $this->groupManager->isInGroup($uid, PermissionService::GROUP_HELPDESK_ADMINS)) {
			return 'admin';
		}
		return 'agent';
	}

	/**
	 * Denied-role refinement (spec NH-06): requester-scope uids get a
	 * dedicated wire value so the client can show tailored copy + the
	 * portal deep link instead of the generic staff-only wall. Older
	 * clients fail closed on the unknown enum (same as 'denied').
	 * Guest containment keeps precedence — a customer+requester uid stays
	 * 'denied' (D-R04), and no seat is ever implied by this role (NN-11).
	 */
	private function resolveDeniedVariant(string $uid): string
	{
		try {
			if ($uid === '' || $this->userManager->get($uid) === null) {
				return 'denied';
			}
			if ($this->groupManager->isInGroup($uid, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
				return 'denied';
			}
			if ($this->permissions->isRequesterGroupMember($uid)) {
				return 'requester';
			}
		} catch (\Throwable) {
			// Role resolution fails closed.
		}
		return 'denied';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function bootstrapPayload(string $uid, string $displayName, string $appVersion): array
	{
		$gate = $this->license->gateState($uid);
		$status = $this->license->status();
		$validUntil = is_array($status['state'] ?? null) ? ($status['state']['validUntil'] ?? null) : null;
		$role = $this->resolveRole($uid);
		$envelope = $this->license->buildEnvelope();
		$envelopeWire = null;
		if (is_array($envelope)) {
			$envelopeWire = Tkc2Codec::FORMAT . '.' . $envelope['payloadB64'] . '.' . $envelope['signatureB64'];
		}

		$maxAttachment = (int)$this->config->getAppValue('ticketcheck', 'max_attachment_size', (string)(10 * 1024 * 1024));
		if ($maxAttachment < 1) {
			$maxAttachment = 10 * 1024 * 1024;
		}

		$staff = in_array($role, ['agent', 'admin'], true);
		$pushEnabled = $staff && ($this->push?->isFeatureEnabled() ?? false);
		$features = $staff ? self::FEATURES : [];
		if ($this->canAccessRequesterCompanion($uid)) {
			$features[] = 'requesterPortal';
		}
		if ($pushEnabled) {
			$features[] = 'push';
		}

		return [
			'ok' => true,
			'server' => [
				'appVersion' => $appVersion,
				'companionMin' => self::COMPANION_MIN,
			],
			'user' => [
				'id' => $uid,
				'displayName' => $displayName,
				'role' => $role,
			],
			'licensing' => [
				'product' => Tkc2Codec::PRODUCT,
				'prefix' => Tkc2Codec::FORMAT,
				'envelope' => $envelopeWire,
				'vendorPublicKeyB64' => VendorPublicKey::publicKeyB64(),
				'seat' => [
					'assigned' => $staff && $gate['seatAssigned'] && $gate['seatWithinLimit'] && $gate['licenseValid'],
					'validUntil' => is_string($validUntil) ? $validUntil : null,
				],
			],
			'limits' => [
				'maxAttachmentBytes' => $maxAttachment,
				'maxCommentChars' => 10000,
			],
			'capabilities' => [
				'companionMin' => self::COMPANION_MIN,
				'features' => $features,
				// Honest: true only when the ops flag AND the Expo access token
				// are configured — registered tokens without delivery stay
				// `delivery: deferred` (CompanionController::pushRegister).
				'pushAvailable' => $pushEnabled,
			],
		];
	}
}
