<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Facade;

use OCA\Ticketcheck\Db\HelpdeskCustomer;
use OCA\Ticketcheck\Db\HelpdeskCustomerMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;

/**
 * Read-only TicketCheck helpdesk surface for companion CRM apps.
 */
class CrmHelpdeskReadFacade
{
	public const FACADE_VERSION = 1;

	public function __construct(
		private readonly HelpdeskCustomerMapper $customerMapper,
		private readonly TicketMapper $ticketMapper,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/** @return array<string, mixed>|null */
	public function getCustomer(int $id): ?array
	{
		try {
			return $this->customerDto($this->customerMapper->find($id));
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listCustomers(int $limit = 500): array
	{
		$customers = $this->customerMapper->findAll(max(1, min(2000, $limit)), 0);
		return array_values(array_map(fn (HelpdeskCustomer $c) => $this->customerDto($c), $customers));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function searchCustomers(string $q, int $limit = 50): array
	{
		$q = trim($q);
		if ($q === '') {
			return [];
		}
		return array_values(array_map(
			fn (HelpdeskCustomer $c) => $this->customerDto($c),
			$this->customerMapper->search($q, max(1, min(200, $limit))),
		));
	}

	/**
	 * @return array{open: int, waiting: int, resolved30d: int}
	 */
	public function countTickets(int $customerId): array
	{
		$open = $this->ticketMapper->search('', [
			'customer_id' => $customerId,
			'status_in' => ['new', 'in_progress', 'waiting'],
		]);
		$waiting = $this->ticketMapper->search('', [
			'customer_id' => $customerId,
			'status' => Ticket::STATUS_WAITING,
		]);

		return [
			'open' => count($open),
			'waiting' => count($waiting),
			'resolved30d' => 0,
		];
	}

	/**
	 * @return list<array{id: int, subject: string, status: string, deepLink: string}>
	 */
	public function listOpenTickets(int $customerId, int $limit = 50): array
	{
		$tickets = $this->ticketMapper->search('', [
			'customer_id' => $customerId,
			'status_in' => ['new', 'in_progress', 'waiting'],
		]);
		return $this->mapTickets(array_slice($tickets, 0, max(1, min(200, $limit))));
	}

	/**
	 * Waiting-on-us tickets across the helpdesk (Today queue §14.5).
	 *
	 * @return list<array{id: int, subject: string, companyId: null, deepLink: string, customerId: int}>
	 */
	public function listWaitingTickets(int $limit = 50): array
	{
		$tickets = $this->ticketMapper->search('', [
			'status' => Ticket::STATUS_WAITING,
		]);
		$out = [];
		foreach (array_slice($tickets, 0, max(1, min(200, $limit))) as $ticket) {
			$out[] = [
				'id' => (int)$ticket->getId(),
				'subject' => (string)$ticket->getTitle(),
				'companyId' => null,
				'customerId' => (int)($ticket->getCustomerId() ?? 0),
				'deepLink' => $this->urlGenerator->linkToRouteAbsolute(
					'ticketcheck.ticket.show',
					['id' => (int)$ticket->getId()],
				),
			];
		}
		return $out;
	}

	/**
	 * @param list<Ticket> $tickets
	 * @return list<array{id: int, subject: string, status: string, deepLink: string}>
	 */
	private function mapTickets(array $tickets): array
	{
		$out = [];
		foreach ($tickets as $ticket) {
			$out[] = [
				'id' => (int)$ticket->getId(),
				'subject' => (string)$ticket->getTitle(),
				'status' => (string)$ticket->getStatus(),
				'deepLink' => $this->urlGenerator->linkToRouteAbsolute(
					'ticketcheck.ticket.show',
					['id' => (int)$ticket->getId()],
				),
			];
		}
		return $out;
	}

	/** @return array<string, mixed> */
	private function customerDto(HelpdeskCustomer $customer): array
	{
		return [
			'id' => (int)$customer->getId(),
			'name' => (string)$customer->getName(),
			'email' => $customer->getEmail(),
			'phone' => method_exists($customer, 'getPhone') ? $customer->getPhone() : null,
		];
	}
}
