<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for guest sidebar stats + portal status summary:
 * guest-scope counting (created_by ∪ email-addressed ∩ accessible projects),
 * done-alias handling, and the canonical statusSummary buckets.
 * Every mutant below must make the filtered suite FAIL.
 *
 * Run: php tests/Mutation/run-guest-stats-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$mapperTarget = $appRoot . '/lib/Db/TicketMapper.php';
$navTarget = $appRoot . '/lib/Service/NavigationContextService.php';
$displayTarget = $appRoot . '/lib/Service/PortalTicketDisplay.php';
$ticketTarget = $appRoot . '/lib/Db/Ticket.php';
$phpunit = $appRoot . '/vendor/bin/phpunit';
$config = $appRoot . '/phpunit.xml';
$filter = 'GuestScopeCount|PortalTicketDisplayStatusSummary|NavigationContextService|TicketMapperOpenTickets';

if (!is_file($phpunit)) {
	fwrite(STDERR, "PHPUnit missing\n");
	exit(1);
}

function run(string $phpunit, string $config, string $filter): int
{
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter) . ' 2>&1';
	exec($cmd, $out, $code);
	return (int)$code;
}

$targets = [
	$mapperTarget,
	$navTarget,
	$displayTarget,
	$ticketTarget,
];
$originals = [];
foreach ($targets as $target) {
	$originals[$target] = file_get_contents($target);
	if ($originals[$target] === false) {
		fwrite(STDERR, "Cannot read mutation target {$target}\n");
		exit(1);
	}
}

$restoreAll = static function () use ($targets, $originals): void {
	foreach ($targets as $target) {
		file_put_contents($target, $originals[$target]);
	}
};

echo "== Baseline ==\n";
if (run($phpunit, $config, $filter) !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}
echo "Baseline green.\n";

$mutations = [
	[
		'label' => 'Guest scope loses email-addressed project tickets',
		'file' => $mapperTarget,
		'search' => "if (\$normalized !== '' && \$projectIds !== []) {",
		'replace' => "if (false) {",
	],
	[
		'label' => 'Guest scope: own tickets escape the project check',
		'file' => $mapperTarget,
		'search' => "\$expr->eq('created_by', \$qb->createNamedParameter(\$userId)),\n                    \$ownProjectScope",
		'replace' => "\$expr->eq('created_by', \$qb->createNamedParameter(\$userId))",
	],
	[
		'label' => 'Guest open count ignores legacy done aliases',
		'file' => $mapperTarget,
		'search' => "if (\$openOnly) {\n                \$qb->andWhere(\$expr->notIn('status', \$qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));\n            }",
		'replace' => "if (\$openOnly) {\n                \$qb->andWhere(\$expr->neq('status', \$qb->createNamedParameter(Ticket::STATUS_DONE)));\n            }",
	],
	[
		'label' => 'Guest sidebar falls back to email-only scope',
		'file' => $navTarget,
		'search' => "'total' => \$this->ticketMapper->countGuestScope(\$userId, \$email, \$projectIds, false),",
		'replace' => "'total' => \$this->ticketMapper->countByCustomerEmail(\$email),",
	],
	[
		'label' => 'statusSummary: open never subtracts done',
		'file' => $displayTarget,
		'search' => "\$summary['open'] = \$summary['total'] - \$summary[Ticket::STATUS_DONE];",
		'replace' => "\$summary['open'] = \$summary['total'];",
	],
	[
		'label' => 'statusSummary: raw statuses skip normalization',
		'file' => $displayTarget,
		'search' => "\$normalized = Ticket::normalizeStatus(\$status);",
		'replace' => "\$normalized = \$status === null ? null : strtolower(trim(\$status));",
	],
	[
		'label' => 'Ticket::isOpen treats only literal done as closed',
		'file' => $ticketTarget,
		'search' => "return self::normalizeStatus(\$this->getStatus()) !== self::STATUS_DONE;",
		'replace' => "return \$this->getStatus() !== self::STATUS_DONE;",
	],
];

$failures = 0;
foreach ($mutations as $mutation) {
	echo "== Mutant: {$mutation['label']} (must FAIL) ==\n";
	$mutated = str_replace($mutation['search'], $mutation['replace'], $originals[$mutation['file']], $count);
	if ($count < 1) {
		$restoreAll();
		fwrite(STDERR, "Could not apply {$mutation['label']}\n");
		exit(1);
	}
	file_put_contents($mutation['file'], $mutated);
	$mutCode = run($phpunit, $config, $filter);
	$restoreAll();
	if ($mutCode === 0) {
		fwrite(STDERR, "MUTATION SURVIVED: {$mutation['label']}\n");
		$failures++;
		continue;
	}
	echo "Mutation killed (exit {$mutCode}). OK.\n";
}

if ($failures > 0) {
	fwrite(STDERR, "{$failures} mutation(s) survived.\n");
	exit(1);
}

echo "All guest stats mutations killed.\n";
exit(0);
