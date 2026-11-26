<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: TC-MULTI-ASSIGN.
 *
 * PHP mutants are killed by TicketAssigneeServiceTest; template/JS/registry
 * mutants are killed by the node contract test (multi-assignee-contract).
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-multi-assignee-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$appId = basename($appRoot);
$workspaceRoot = dirname($appRoot, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';

function run_phpunit(string $appRoot, string $workspaceRoot, string $appId, string $phpunit): int
{
	$filter = 'TicketAssigneeServiceTest';
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
		. ' && node --test tests/js/multi-assignee-contract.test.mjs 2>&1';
	passthru($cmd, $code);
	return (int)$code;
}

/**
 * @return list<array{file:string,from:string,to:string,label:string,kill:'phpunit'|'node'}>
 */
function multi_assignee_mutations(string $appRoot): array
{
	$svc = $appRoot . '/lib/Service/TicketAssigneeService.php';
	return [
		// already_primary guard — dropping it lets a uid hold primary AND co.
		[
			'file' => $svc,
			'from' => "if (\$userId === (string)\$ticket->getAssignedTo()) {\n\t\t\t\t\tthrow new \\InvalidArgumentException('already_primary');\n\t\t\t\t}",
			'to' => "if (false) {\n\t\t\t\t\tthrow new \\InvalidArgumentException('already_primary');\n\t\t\t\t}",
			'label' => 'already_primary_guard_dropped',
			'kill' => 'phpunit',
		],
		// cap check — dropping it admits unbounded co-assignees.
		[
			'file' => $svc,
			'from' => "if (\$this->assigneeMapper->countByTicketId(\$ticketId) >= self::MAX_CO_ASSIGNEES) {\n\t\t\t\t\tthrow new \\InvalidArgumentException('max_coassignees');\n\t\t\t\t}",
			'to' => "if (false) {\n\t\t\t\t\tthrow new \\InvalidArgumentException('max_coassignees');\n\t\t\t\t}",
			'label' => 'cap_check_dropped',
			'kill' => 'phpunit',
		],
		// unique-race idempotency — swallowing the catch turns a lost race
		// into a 500 instead of set-semantics success.
		[
			'file' => $svc,
			'from' => "if (\$e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {\n\t\t\t\t\t\treturn false; // concurrent add won; set semantics → success\n\t\t\t\t\t}",
			'to' => "if (false) {\n\t\t\t\t\t\treturn false; // concurrent add won; set semantics → success\n\t\t\t\t\t}",
			'label' => 'unique_race_swallow_removed',
			'kill' => 'phpunit',
		],
		// EC-19 silent dedupe — removing it must re-surface already_primary…
		// here we invert: primary uid stays in $target → assertAssignable
		// still passes for valid agents → the row inserts; test asserts the
		// insert only carried 'bob'.
		[
			'file' => $svc,
			'from' => "\$target = array_values(array_filter(\$target, static fn (string \$uid): bool => \$uid !== \$primary));",
			'to' => "\$target = array_values(\$target);",
			'label' => 'ec19_primary_dedupe_dropped',
			'kill' => 'phpunit',
		],
		// team assign authz — dropping the member/admin gate lets anyone
		// fan out arbitrary groups.
		[
			'file' => $svc,
			'from' => "if (!\$this->groupManager->isAdmin(\$actor)\n\t\t\t\t\t&& !\$this->groupManager->isInGroup(\$actor, \$groupId)) {",
			'to' => "if (false) {",
			'label' => 'team_assign_authz_dropped',
			'kill' => 'phpunit',
		],
		// claim membership gate — the entire point of claim.
		[
			'file' => $svc,
			'from' => "if (\$this->groupManager->isInGroup(\$actor, (string)\$team->getGroupId())) {\n\t\t\t\t\t\t\$this->teamMapper->delete(\$team);\n\t\t\t\t\t\t\$claimed = true;\n\t\t\t\t\t}",
			'to' => "if (false) {\n\t\t\t\t\t\t\$this->teamMapper->delete(\$team);\n\t\t\t\t\t\t\$claimed = true;\n\t\t\t\t\t}",
			'label' => 'claim_membership_gate_dropped',
			'kill' => 'phpunit',
		],
		// claim must promote NEW tickets to in_progress.
		[
			'file' => $svc,
			'from' => "if (\$ticket->getStatus() === Ticket::STATUS_NEW) {\n\t\t\t\t\t\t\$ticket->setStatus(Ticket::STATUS_IN_PROGRESS);\n\t\t\t\t\t}",
			'to' => "if (false) {\n\t\t\t\t\t\t\$ticket->setStatus(Ticket::STATUS_IN_PROGRESS);\n\t\t\t\t\t}",
			'label' => 'claim_status_promotion_dropped',
			'kill' => 'phpunit',
		],
		// UI wiring mutants — killed by the node contract.
		[
			'file' => $appRoot . '/js/ticket-form.js',
			'from' => "const hidden = document.getElementById('co_assignees');",
			'to' => "const hidden = null;",
			'label' => 'hidden_csv_input_unwired',
			'kill' => 'node',
		],
		[
			'file' => $appRoot . '/js/ticket-form.js',
			'from' => 'MAX_CO = 10',
			'to' => 'MAX_CO = 9999',
			'label' => 'client_cap_removed',
			'kill' => 'node',
		],
		[
			'file' => $appRoot . '/templates/common/tickets-filters.php',
			'from' => "['team' => 'mine']",
			'to' => "['team' => 'x_never']",
			'label' => 'team_pill_param_broken',
			'kill' => 'node',
		],
	];
}

$mutations = multi_assignee_mutations($appRoot);
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
