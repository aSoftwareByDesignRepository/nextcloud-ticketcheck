<?php

declare(strict_types=1);

/**
 * TicketCheck helpdesk customer write surface for companion CRM apps.
 *
 * Server-side only. ACL mirrors customer administration: helpdesk_admins or
 * Nextcloud admins; never guests (helpdesk_customers).
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Public;

use OCA\Ticketcheck\Db\HelpdeskCustomerMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Server-side only. Not an HTTP surface.
 */
class CrmHelpdeskWriteFacade
{
	public const FACADE_VERSION = 1;

	private const SLUG_PATTERN = '/^[a-z0-9-]{3,64}$/';

	public function __construct(
		private readonly ProjectService $projectService,
		private readonly HelpdeskCustomerMapper $customerMapper,
		private readonly IGroupManager $groupManager,
	) {
	}

	public function createCustomer(CrmHelpdeskWriteRequest $req): FacadeResult
	{
		$validation = $this->validateCommon($req, requireExisting: false, requireDisplayName: true);
		if ($validation !== null) {
			return $validation;
		}

		if (!$this->actorMayAdministerCustomers($req->actorUid)) {
			return FacadeResult::failure('permission_denied', 'Actor may not create TicketCheck customers.');
		}

		$existing = $this->customerMapper->findByName($req->displayName);
		if ($existing !== null) {
			return FacadeResult::failure('duplicate_name', 'A helpdesk customer with this name already exists.', [
				'existingTcCustomerId' => (int)$existing->getId(),
			]);
		}

		try {
			$tcCustomerId = $this->projectService->createCustomer(
				$req->displayName,
				$req->email,
				null,
				$req->phone,
				'crm:' . $req->crmCompanySlug,
				$req->actorUid,
			);
		} catch (\Throwable $e) {
			if ($this->isUniqueConstraintViolation($e)
				|| stripos($e->getMessage(), 'already exists') !== false
				|| stripos($e->getMessage(), 'Duplicate') !== false
			) {
				$existing = $this->customerMapper->findByName($req->displayName);
				$data = $existing !== null
					? ['existingTcCustomerId' => (int)$existing->getId()]
					: [];
				return FacadeResult::failure('duplicate_name', 'A helpdesk customer with this name already exists.', $data);
			}
			return FacadeResult::failure('validation_failed', $e->getMessage());
		}

		return FacadeResult::success([
			'tcCustomerId' => $tcCustomerId,
			'displayName' => $req->displayName,
			'created' => true,
		]);
	}

	public function ensureLink(CrmHelpdeskWriteRequest $req): FacadeResult
	{
		$validation = $this->validateCommon($req, requireExisting: true, requireDisplayName: false);
		if ($validation !== null) {
			return $validation;
		}

		if (!$this->actorMayAdministerCustomers($req->actorUid)) {
			return FacadeResult::failure('permission_denied', 'Actor may not link TicketCheck customers.');
		}

		$customerId = (int)$req->existingTcCustomerId;
		try {
			$customer = $this->customerMapper->find($customerId);
		} catch (DoesNotExistException) {
			return FacadeResult::failure('not_found', 'TicketCheck customer not found.');
		}

		return FacadeResult::success([
			'tcCustomerId' => (int)$customer->getId(),
			'displayName' => (string)$customer->getName(),
			'created' => false,
		]);
	}

	private function actorMayAdministerCustomers(string $actorUid): bool
	{
		if ($actorUid === '') {
			return false;
		}
		// Guests must never administer the customer directory (CUC write path).
		if ($this->groupManager->isInGroup($actorUid, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
			return false;
		}
		return $this->groupManager->isAdmin($actorUid)
			|| $this->groupManager->isInGroup($actorUid, PermissionService::GROUP_HELPDESK_ADMINS);
	}

	private function validateCommon(
		CrmHelpdeskWriteRequest $req,
		bool $requireExisting,
		bool $requireDisplayName,
	): ?FacadeResult {
		if ($req->actorUid === '') {
			return FacadeResult::failure('validation_failed', 'actorUid is required.');
		}
		if ($req->crmCompanyId <= 0) {
			return FacadeResult::failure('validation_failed', 'crmCompanyId must be > 0.');
		}
		if (!preg_match(self::SLUG_PATTERN, $req->crmCompanySlug)) {
			return FacadeResult::failure('validation_failed', 'crmCompanySlug must match [a-z0-9-]{3,64}.');
		}
		if ($requireDisplayName && $req->displayName === '') {
			return FacadeResult::failure('validation_failed', 'displayName is required.');
		}
		if ($req->email !== null && filter_var($req->email, FILTER_VALIDATE_EMAIL) === false) {
			return FacadeResult::failure('validation_failed', 'email is invalid.');
		}
		if ($requireExisting && ($req->existingTcCustomerId === null || $req->existingTcCustomerId <= 0)) {
			return FacadeResult::failure('validation_failed', 'existingTcCustomerId is required.');
		}

		return null;
	}

	private function isUniqueConstraintViolation(\Throwable $e): bool
	{
		if ($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
			return true;
		}
		$previous = $e->getPrevious();
		return $previous instanceof \Throwable && $this->isUniqueConstraintViolation($previous);
	}
}
