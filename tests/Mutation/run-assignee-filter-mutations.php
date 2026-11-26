<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: the ticket list / kanban "Assigned To" filter must stay a
 * searchable combobox backed by the assignable-staff roster (issue #10).
 *
 * PHP mutants are killed by TicketControllerFilterOptionsTest; template/JS
 * wiring mutants are killed by the node contract test.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-assignee-filter-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$appId = basename($appRoot);
$workspaceRoot = dirname($appRoot, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';

function run_phpunit(string $appRoot, string $workspaceRoot, string $appId, string $phpunit): int
{
	$filter = 'TicketControllerFilterOptionsTest';
	$inside = is_file('/var/www/html/lib/base.php');
	if ($inside) {
		$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
			. ' --do-not-cache-result --filter ' . escapeshellarg($filter);
	} else {
		$cmd = 'docker compose -f ' . escapeshellarg($workspaceRoot . '/docker-compose.yml')
			. ' exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. '/var/www/html/custom_apps/' . $appId . '/vendor/bin/phpunit '
			. '-c /var/www/html/custom_apps/' . $appId . '/phpunit.xml '
			. '--do-not-cache-result --filter ' . escapeshellarg($filter);
	}
	passthru($cmd, $code);
	return (int)$code;
}

function run_node_contract(string $appRoot): int
{
	$cmd = 'cd ' . escapeshellarg($appRoot)
		. ' && node --test tests/js/assignee-filter-contract.test.mjs 2>&1';
	passthru($cmd, $code);
	return (int)$code;
}

/**
 * @return list<array{file:string,from:string,to:string,label:string,kill:'phpunit'|'node'}>
 */
function assignee_filter_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Controller/TicketController.php',
			'from' => "if (\$type === 'assignee') {",
			'to' => "if (\$type === 'assignee_never') {",
			'label' => 'assignee_branch_removed',
			'kill' => 'phpunit',
		],
		[
			'file' => $appRoot . '/lib/Controller/TicketController.php',
			'from' => "'value' => (string)\$user['user_id'],",
			'to' => "'value' => '',",
			'label' => 'assignee_option_value_dropped',
			'kill' => 'phpunit',
		],
		[
			'file' => $appRoot . '/lib/Controller/TicketController.php',
			'from' => '                if ($this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {',
			'to' => '                if (false && $this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {',
			'label' => 'customer_group_exclusion_dropped',
			'kill' => 'phpunit',
		],
		[
			'file' => $appRoot . '/templates/common/tickets-filters.php',
			'from' => 'id="filter-assigned" class="tc-filter-picker__native tc-select-combobox__native"',
			'to' => 'id="filter-assigned" class="tc-filter-picker__native"',
			'label' => 'native_select_loses_combobox_class',
			'kill' => 'node',
		],
		[
			'file' => $appRoot . '/js/tickets.js',
			'from' => "type: 'assignee',",
			'to' => "type: 'customer',",
			'label' => 'assignee_wiring_reverts_to_customer_type',
			'kill' => 'node',
		],
		[
			'file' => $appRoot . '/lib/Controller/TicketController.php',
			'from' => "'assignedToFilterOption' => ",
			'to' => "'assignedToFilterOption_removed' => ",
			'label' => 'applied_assignee_label_not_passed',
			'kill' => 'node',
		],
	];
}

$mutations = assignee_filter_mutations($appRoot);
$failed = 0;
$killed = 0;

echo "Baseline phpunit…\n";
if (run_phpunit($appRoot, $workspaceRoot, $appId, $phpunit) !== 0) {
	fwrite(STDERR, "Baseline phpunit failed — aborting mutations.\n");
	exit(1);
}
echo "Baseline node contract…\n";
if (run_node_contract($appRoot) !== 0) {
	fwrite(STDERR, "Baseline node contract failed — aborting mutations.\n");
	exit(1);
}

foreach ($mutations as $m) {
	$original = (string)file_get_contents($m['file']);
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "SKIP (needle missing): {$m['label']}\n");
		$failed++;
		continue;
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $original));
	echo "Mutant {$m['label']}…\n";
	$code = $m['kill'] === 'phpunit'
		? run_phpunit($appRoot, $workspaceRoot, $appId, $phpunit)
		: run_node_contract($appRoot);
	file_put_contents($m['file'], $original);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$m['label']}\n");
		$failed++;
	} else {
		echo "Killed: {$m['label']}\n";
		$killed++;
	}
}

echo "\n{$killed} killed, {$failed} survived/skipped.\n";
exit($failed === 0 ? 0 : 1);
