<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use OCA\Ticketcheck\Service\MigrationValidationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MigrationValidationServiceIndexTest extends TestCase
{
    public function testTableHasIndexNamed_IsCaseInsensitive(): void
    {
        $table = new Table('oc_helpdesk_tickets');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('status', Types::STRING, ['length' => 32]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['status'], 'hd_status_idx');

        $m = new ReflectionMethod(MigrationValidationService::class, 'tableHasIndexNamed');
        $m->setAccessible(true);

        self::assertTrue($m->invoke(null, $table, 'HD_STATUS_IDX'));
        self::assertFalse($m->invoke(null, $table, 'hd_missing_idx'));
    }

    public function testFindIntrospectedTable_OracleUppercase(): void
    {
        $table = new Table('OC_HELPDESK_TICKETS');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);
        $schema = new Schema([$table]);

        $db = $this->createMock(\OCP\IDBConnection::class);
        $db->method('getDatabaseProvider')->willReturn(\OCP\IDBConnection::PLATFORM_ORACLE);

        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getSystemValueString')->with('dbtableprefix', 'oc_')->willReturn('oc_');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $svc = new MigrationValidationService($db, $logger, $config);
        $ref = new ReflectionMethod(MigrationValidationService::class, 'findIntrospectedTable');
        $ref->setAccessible(true);

        $found = $ref->invoke($svc, $schema, 'helpdesk_tickets');
        self::assertNotNull($found);
        self::assertSame('OC_HELPDESK_TICKETS', $found->getName());
    }
}
