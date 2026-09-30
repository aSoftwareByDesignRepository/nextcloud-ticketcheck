<?php

declare(strict_types=1);

/**
 * Escalation rule mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Escalation rule mapper
 */
class EscalationRuleMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_escalation_rules', EscalationRule::class);
    }

    /**
     * Find rule by ID
     */
    public function find(int $id): EscalationRule
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Find all active escalation rules
     *
     * @return EscalationRule[]
     */
    public function findAllActive(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Find all rules
     *
     * @return EscalationRule[]
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Insert a new rule
     */
    public function insertRule(EscalationRule $rule): EscalationRule
    {
        return $this->insert($rule);
    }

    /**
     * Update an existing rule
     */
    public function updateRule(EscalationRule $rule): EscalationRule
    {
        return $this->update($rule);
    }

    /**
     * Delete a rule
     */
    public function deleteRule(EscalationRule $rule): EscalationRule
    {
        return $this->delete($rule);
    }
}
