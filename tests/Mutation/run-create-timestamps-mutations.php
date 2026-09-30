<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for TicketCheck create* timestamp / status writes.
 *
 * Usage:
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-create-timestamps-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';

/**
 * @param list<string> $filters
 */
function run_filters(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

$filters = [
	'ProjectServiceCreateCustomerTimestampsTest',
	'TemplateServiceTimestampsTest',
];

echo "== baseline ==\n";
if (run_filters($appRoot, $phpunit, $filters) !== 0) {
	fwrite(STDERR, "Baseline failed; aborting mutations\n");
	exit(1);
}

$mutations = [
	[
		'file' => $appRoot . '/lib/Service/ProjectService.php',
		'from' => "'updated_at' => \$qb->createNamedParameter(\$now, IQueryBuilder::PARAM_DATE),\n                'created_by' => \$qb->createNamedParameter(\$createdBy),\n            ]);\n\n        \$qb->executeStatement();\n        return (int)\$qb->getLastInsertId();\n    }\n\n    /**\n     * Create customer",
		'to' => "'created_by' => \$qb->createNamedParameter(\$createdBy),\n            ]);\n\n        \$qb->executeStatement();\n        return (int)\$qb->getLastInsertId();\n    }\n\n    /**\n     * Create customer",
		'label' => 'drop createProject updated_at',
	],
	[
		'file' => $appRoot . '/lib/Service/ProjectService.php',
		'from' => "'status' => \$qb->createNamedParameter(\$active ? 'active' : 'inactive'),",
		'to' => "'status' => \$qb->createNamedParameter('active'),",
		'label' => 'hardcode createProject status active',
	],
	[
		'file' => $appRoot . '/lib/Service/TemplateService.php',
		'from' => "'created_at' => \$qb->createNamedParameter(\$now, IQueryBuilder::PARAM_DATE),\n                'updated_at' => \$qb->createNamedParameter(\$now, IQueryBuilder::PARAM_DATE),",
		'to' => "'created_at' => \$qb->createNamedParameter(\$now, IQueryBuilder::PARAM_DATE),",
		'label' => 'drop createTemplate updated_at',
	],
];

$failed = [];
foreach ($mutations as $m) {
	$source = $m['file'];
	$backup = $source . '.mutation-bak';
	echo "\n== mutation: {$m['label']} ==\n";
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $m['from'])) {
		$failed[] = $m['label'] . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	file_put_contents($source, str_replace($m['from'], $m['to'], $original));
	$code = run_filters($appRoot, $phpunit, $filters);
	restore($source, $backup);
	if ($code === 0) {
		$failed[] = $m['label'];
		echo "MUTATION SURVIVED: {$m['label']}\n";
	} else {
		echo "killed {$m['label']}\n";
	}
}

if ($failed !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failed) . "\n");
	exit(1);
}

echo "\nAll ticketcheck create-timestamp mutations killed.\n";
exit(0);
