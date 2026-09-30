<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Exception\LicenseException;
use OCA\Ticketcheck\Service\LicenseService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class LicenseController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PermissionService $permissions,
		private readonly LicenseService $license,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function show(): JSONResponse
	{
		try {
			$this->requireAppAdmin();
			return new JSONResponse($this->license->status());
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	#[NoAdminRequired]
	public function apply(): JSONResponse
	{
		try {
			$uid = $this->requireAppAdmin();
			$key = $this->request->getParam('key');
			if (!is_string($key) || trim($key) === '') {
				return new JSONResponse([
					'ok' => false,
					'error' => 'license_invalid',
					'message' => 'A license key is required.',
				], Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			return new JSONResponse($this->license->apply($uid, $key));
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	#[NoAdminRequired]
	public function remove(): JSONResponse
	{
		try {
			$this->requireAppAdmin();
			return new JSONResponse($this->license->remove());
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	#[NoAdminRequired]
	public function seats(): JSONResponse
	{
		try {
			$this->requireAppAdmin();
			$limit = (int)$this->request->getParam('limit', 50);
			$offset = (int)$this->request->getParam('offset', 0);
			return new JSONResponse($this->license->listSeats($limit, $offset));
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	#[NoAdminRequired]
	public function assignSeat(): JSONResponse
	{
		try {
			$uid = $this->requireAppAdmin();
			$result = $this->license->assignSeat($uid, $this->request->getParam('userId'));
			return new JSONResponse(
				$result['seat'],
				$result['created'] ? Http::STATUS_CREATED : Http::STATUS_OK,
			);
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	#[NoAdminRequired]
	public function removeSeat(string $uid): JSONResponse
	{
		try {
			$this->requireAppAdmin();
			$this->license->removeSeat($uid);
			return new JSONResponse(['deleted' => true]);
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	/**
	 * Directory search for seat assignment (agents/admins only; never guests).
	 *
	 * Intentional product scope: TicketCheck Mobile seats are for helpdesk
	 * staff, not portal customers. Searching the full Nextcloud directory would
	 * offer customer accounts as seat candidates.
	 */
	#[NoAdminRequired]
	public function searchUsers(): JSONResponse
	{
		try {
			$this->requireAppAdmin();
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}

		$q = trim((string)$this->request->getParam('q', $this->request->getParam('query', '')));
		if (mb_strlen($q) < 2) {
			return new JSONResponse(['ok' => true, 'items' => []]);
		}
		$needle = mb_strtolower($q, 'UTF-8');
		$items = [];
		$seen = [];
		foreach ([PermissionService::GROUP_HELPDESK_ADMINS, PermissionService::GROUP_HELPDESK_AGENTS] as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				$uid = $user->getUID();
				if (isset($seen[$uid]) || $this->groupManager->isInGroup($uid, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
					continue;
				}
				$display = $user->getDisplayName();
				$hay = mb_strtolower($uid . ' ' . $display, 'UTF-8');
				if (!str_contains($hay, $needle)) {
					continue;
				}
				$seen[$uid] = true;
				$items[] = [
					'id' => $uid,
					'displayName' => $display,
				];
				if (count($items) >= 25) {
					return new JSONResponse(['ok' => true, 'items' => $items]);
				}
			}
		}
		return new JSONResponse(['ok' => true, 'items' => $items]);
	}

	private function requireAppAdmin(): string
	{
		$user = $this->userSession->getUser();
		$uid = $user?->getUID() ?? '';
		if ($uid === '' || !$this->permissions->isAppAdmin($uid)) {
			throw new LicenseException('access_denied', 'App admin required.', 403);
		}
		return $uid;
	}

	private function fromLicenseException(LicenseException $e): JSONResponse
	{
		$status = $e->getHttpStatus();
		if (!in_array($status, [400, 401, 402, 403, 404, 409, 422, 428, 429, 500, 503], true)) {
			$status = 500;
		}
		return new JSONResponse([
			'ok' => false,
			'error' => $e->getErrorCode(),
			'message' => $e->getMessage(),
		], $status);
	}
}
