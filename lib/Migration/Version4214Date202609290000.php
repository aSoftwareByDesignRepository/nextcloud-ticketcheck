<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add checklist_item_id to helpdesk_attachments and helpdesk_comments.
 *
 * Attachment and Comment entities declare checklistItemId (addType included)
 * for the planned checklist feature, but no migration ever created the
 * columns. The fields are dormant today — nothing calls the setters — but
 * the first setChecklistItemId() + insert/update would throw an
 * Unknown-column exception, the same drift class as the
 * helpdesk_guest_access silent-grant failure. Add the columns so the entity
 * contract is honest in both directions.
 */
class Version4214Date202609290000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('helpdesk_attachments')) {
			$table = $schema->getTable('helpdesk_attachments');
			if (!$table->hasColumn('checklist_item_id')) {
				$table->addColumn('checklist_item_id', Types::BIGINT, [
					'notnull' => false,
					'default' => null,
					'unsigned' => true,
				]);
				$changed = true;
				$output->info('helpdesk_attachments: added checklist_item_id column');
			}
			if ($table->hasColumn('checklist_item_id') && !$table->hasIndex('hd_att_chk_idx')) {
				$table->addIndex(['checklist_item_id'], 'hd_att_chk_idx');
				$changed = true;
			}
		}

		if ($schema->hasTable('helpdesk_comments')) {
			$table = $schema->getTable('helpdesk_comments');
			if (!$table->hasColumn('checklist_item_id')) {
				$table->addColumn('checklist_item_id', Types::BIGINT, [
					'notnull' => false,
					'default' => null,
					'unsigned' => true,
				]);
				$changed = true;
				$output->info('helpdesk_comments: added checklist_item_id column');
			}
			if ($table->hasColumn('checklist_item_id') && !$table->hasIndex('hd_cmt_chk_idx')) {
				$table->addIndex(['checklist_item_id'], 'hd_cmt_chk_idx');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
