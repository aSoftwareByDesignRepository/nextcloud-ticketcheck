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
		// Device push delivery is not shipped yet — do not advertise `push`.
		// Clients use unread + pull-to-refresh (AF11) until a real NC push path lands.
		'attachments',
		'filters',
		'sla',
		'watchers',
		'create',
		'bulk',
		'offlineRead',
	];

	public function __construct(
		private readonly PermissionService $permissions,
		private readonly LicenseService $license,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
		private readonly ?IAppManager $appManager = null,
	) {
	}

	public function canAccessCompanion(string $uid): bool
	{
		return $this->permissions->canViewHelpdeskOverview($uid);
	}

	public function resolveRole(string $uid): string
	{
		if (!$this->canAccessCompanion($uid)) {
			return 'denied';
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
					'assigned' => $gate['seatAssigned'] && $gate['seatWithinLimit'] && $gate['licenseValid'],
					'validUntil' => is_string($validUntil) ? $validUntil : null,
				],
			],
			'limits' => [
				'maxAttachmentBytes' => $maxAttachment,
				'maxCommentChars' => 10000,
			],
			'capabilities' => [
				'companionMin' => self::COMPANION_MIN,
				'features' => self::FEATURES,
				// Honest: Notifications app may exist for web, but companion has no
				// device-token push path yet. Never claim pushAvailable=true.
				'pushAvailable' => false,
			],
		];
	}
}
