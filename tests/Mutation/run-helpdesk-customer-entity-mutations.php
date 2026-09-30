<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for HelpdeskCustomer updatedAt hydration + createCustomer timestamps.
 *
 * Usage (Docker):
 *   docker compose exec -u 1000:1000 nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-helpdesk-customer-entity-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';

/**
 * @param list<string> $filters
 */
function run_filters(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$composeDir = dirname($appRoot, 2);
	$dockerPhpunit = '/var/www/html/custom_apps/ticketcheck/vendor/bin/phpunit';
	$dockerConfig = '/var/www/html/custom_apps/ticketcheck/phpunit.xml';
	if (is_file($composeDir . '/docker-compose.yml')) {
		$probe = 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
			. ' exec -T -u www-data nextcloud php -r ' . escapeshellarg('echo "ok";');
		exec($probe . ' 2>/dev/null', $probeOut, $probeCode);
		if ($probeCode === 0) {
			$cmd = 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
				. ' exec -T -u www-data nextcloud php '
				. escapeshellarg($dockerPhpunit)
				. ' -c ' . escapeshellarg($dockerConfig)
				. ' --filter ' . escapeshellarg($filter);
			passthru($cmd, $code);
			return (int)$code;
		}
	}
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

$filtersAll = [
	'HelpdeskCustomerFromRowTest',
	'ProjectServiceCreateCustomerTimestampsTest',
	'HelpdeskCustomerUpdatedAtContractTest',
];

echo "== baseline ==\n";
if (run_filters($appRoot, $phpunit, $filtersAll) !== 0) {
	fwrite(STDERR, "Baseline failed; aborting mutations\n");
	exit(1);
}

$mutations = [
	[
		'file' => $appRoot . '/lib/Db/HelpdeskCustomer.php',
		'from' => "\$this->addType('updatedAt', 'datetime');",
		'to' => "/* mutated: no updatedAt type */",
		'filter' => ['HelpdeskCustomerFromRowTest'],
		'label' => 'drop updatedAt property type registration',
	],
	[
		'file' => $appRoot . '/lib/Db/HelpdeskCustomer.php',
		'from' => "\tprotected \$updatedAt;\n",
		'to' => '',
		'filter' => ['HelpdeskCustomerFromRowTest'],
		'label' => 'drop updatedAt property (prod BadFunctionCallException)',
	],
	[
		'file' => $appRoot . '/lib/Db/HelpdeskCustomer.php',
		'from' => "if (array_key_exists('updated_at', \$row) && self::isInvalidSqlDate(\$row['updated_at'])) {\n\t\t\t\$fallback = \$row['created_at'] ?? null;\n\t\t\t\$row['updated_at'] = self::isInvalidSqlDate(\$fallback) ? null : \$fallback;\n\t\t}",
		'to' => '/* mutated: no zero-date coercion */',
		'filter' => ['HelpdeskCustomerFromRowTest'],
		'label' => 'skip zero-date updated_at coercion',
	],
	[
		'file' => $appRoot . '/lib/Service/ProjectService.php',
		'from' => "'updated_at' => \$qb->createNamedParameter(\$now, IQueryBuilder::PARAM_DATE),\n                'created_by' => \$qb->createNamedParameter(\$createdBy),",
		'to' => "'created_by' => \$qb->createNamedParameter(\$createdBy),",
		'filter' => ['ProjectServiceCreateCustomerTimestampsTest', 'HelpdeskCustomerUpdatedAtContractTest'],
		'label' => 'createCustomer omits updated_at',
	],
	[
		'file' => $appRoot . '/lib/Migration/Version4211Date20260818224500.php',
		'from' => "\$table->addColumn('updated_at', Types::DATETIME, [\n\t\t\t'notnull' => false,\n\t\t]);",
		'to' => '/* mutated: skip adding updated_at */',
		'filter' => ['HelpdeskCustomerUpdatedAtContractTest'],
		'label' => 'migration skips adding updated_at',
	],
	[
		'file' => $appRoot . '/lib/Service/ProjectService.php',
		'from' => "'company' => \$qb->createNamedParameter(\$company),\n                'phone' => \$qb->createNamedParameter(\$phone),",
		'to' => "'phone' => \$qb->createNamedParameter(\$phone),",
		'filter' => ['ProjectServiceCreateCustomerTimestampsTest'],
		'label' => 'createCustomer omits company',
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
		fwrite(STDERR, "anchor missing: {$m['label']}\n");
		continue;
	}
	file_put_contents($backup, $original);
	file_put_contents($source, str_replace($m['from'], $m['to'], $original));
	$code = run_filters($appRoot, $phpunit, $m['filter']);
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

echo "\nAll helpdesk-customer entity mutations killed.\n";
exit(0);
