<?php

declare(strict_types=1);

/**
 * Mutation: every suppression gate of the account-less external leg must be
 * load-bearing — claim dedupe (NN-05), hourly cap boundary (US-02 AC-6),
 * placeholder/daemon denylist (NN-02), self-author (MH-09), kill switch
 * (US-05), invalid address, pre-send ledger write, and the internal-comment
 * gate (NN-04).
 *
 *   php tests/Mutation/run-external-leg-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

function run_tests(string $appRoot, string $phpunit): int {
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg('EmailServiceExternalLegTest|TicketServiceNotificationDispatchTest');
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

echo "== baseline external-leg gates ==\n";
if (run_tests($appRoot, $phpunit) !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$es = $appRoot . '/lib/Service/EmailService.php';
$ts = $appRoot . '/lib/Service/TicketService.php';

$mutations = [
	[
		'name' => 'drop_invalid_gate',
		'source' => $es,
		'from' => "        if (\$e === '' || !filter_var(\$e, FILTER_VALIDATE_EMAIL)) {\n            return self::REASON_INVALID_EMAIL;\n        }\n",
		'to' => '',
	],
	[
		'name' => 'drop_kill_switch',
		'source' => $es,
		'from' => "        if (!\$this->isExternalCustomerMailEnabled()) {\n            return self::REASON_DISABLED;\n        }\n",
		'to' => '',
	],
	[
		'name' => 'drop_claim_gate',
		'source' => $es,
		'from' => "        if (isset(\$claimed[\$e])) {\n            return self::REASON_CLAIMED;\n        }\n",
		'to' => '',
	],
	[
		'name' => 'drop_self_author',
		'source' => $es,
		'from' => "        if (\$authorEmail !== null && \$authorEmail !== '' && \$e === strtolower(trim(\$authorEmail))) {\n            return self::REASON_SELF_AUTHOR;\n        }\n",
		'to' => '',
	],
	[
		'name' => 'drop_placeholder',
		'source' => $es,
		'from' => "        if (\$this->isPlaceholderDomain(\$e)) {\n            return self::REASON_PLACEHOLDER;\n        }\n",
		'to' => '',
	],
	[
		'name' => 'drop_daemon',
		'source' => $es,
		'from' => "        if (\$this->isDaemonOrOwnAddress(\$e)) {\n            return self::REASON_DAEMON;\n        }\n",
		'to' => '',
	],
	[
		'name' => 'drop_cap_gate',
		'source' => $es,
		'from' => "        if (\$this->externalLegCapped((int)\$ticket->getId(), \$e)) {\n            \$this->bumpExternalCapDrops();\n            return self::REASON_RATE_CAP;\n        }\n",
		'to' => '',
	],
	[
		'name' => 'cap_gte_to_gt',
		'source' => $es,
		'from' => ") >= self::EXTERNAL_LEG_HOURLY_CAP;",
		'to' => ") > self::EXTERNAL_LEG_HOURLY_CAP;",
	],
	[
		'name' => 'skip_ledger_write',
		'source' => $es,
		'from' => "        \$ledgerRowId = null;\n        if (\$this->mailOutboxMapper !== null) {\n            try {\n                \$ledgerRowId = \$this->mailOutboxMapper->recordExternalLegSend(",
		'to' => "        \$ledgerRowId = null;\n        if (false && \$this->mailOutboxMapper !== null) {\n            try {\n                \$ledgerRowId = \$this->mailOutboxMapper->recordExternalLegSend(",
	],
	[
		'name' => 'internal_comment_dispatches',
		'source' => $ts,
		'from' => "        if (!\$isInternal && \$notifyTicket instanceof Ticket) {",
		'to' => "        if (\$notifyTicket instanceof Ticket) {",
	],
];

$failed = [];
foreach ($mutations as $mutation) {
	$name = $mutation['name'];
	$source = $mutation['source'];
	$backup = $source . '.mutation-bak';
	echo "\n== mutation: {$name} ==\n";
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $mutation['from'])) {
		$failed[] = $name . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	try {
		file_put_contents($source, str_replace($mutation['from'], $mutation['to'], $original));
		$code = run_tests($appRoot, $phpunit);
		if ($code === 0) {
			$failed[] = $name;
			echo "MUTATION SURVIVED: {$name}\n";
		} else {
			echo "killed {$name}\n";
		}
	} finally {
		restore($source, $backup);
	}
}

if ($failed !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failed) . "\n");
	exit(1);
}
echo "\nAll external-leg mutations killed.\n";
exit(0);
