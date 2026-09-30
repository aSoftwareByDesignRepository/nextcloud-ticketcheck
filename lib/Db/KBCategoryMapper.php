<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class KBCategoryMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_kb_categories', KBCategory::class);
    }

    /**
     * @return KBCategory[]
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('position', 'ASC')
            ->addOrderBy('name', 'ASC');
        return $this->findEntities($qb);
    }

    public function find(int $id): KBCategory
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }
}
