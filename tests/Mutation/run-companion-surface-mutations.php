<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the companion API surface (S-T13 / plan WP-102):
 * IDOR opacity, transition deny, version CAS, bulk cap, overdue flag,
 * capability honesty. Every mutant below must make the companion suite FAIL.
 *
 * Run: php tests/Mutation/run-companion-surface-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$serviceTarget = $appRoot . '/lib/Service/CompanionTicketService.php';
$gateTarget = $appRoot . '/lib/Service/CompanionGateService.php';
$phpunit = $appRoot . '/vendor/bin/phpunit';
$config = $appRoot . '/phpunit.xml';
$filter = 'Companion|ClientLicenseMiddleware|TicketNextUpdatedAt';

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

$serviceOriginal = file_get_contents($serviceTarget);
$gateOriginal = file_get_contents($gateTarget);
if ($serviceOriginal === false || $gateOriginal === false) {
	fwrite(STDERR, "Cannot read mutation targets\n");
	exit(1);
}

$restore = static function () use ($serviceTarget, $serviceOriginal, $gateTarget, $gateOriginal): void {
	file_put_contents($serviceTarget, $serviceOriginal);
	file_put_contents($gateTarget, $gateOriginal);
};

echo "== Baseline ==\n";
if (run($phpunit, $config, $filter) !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}
echo "Baseline green.\n";

$mutations = [
	[
		'label' => 'IDOR opacity: drop canViewTicket gate',
		'file' => $serviceTarget,
		'original' => $serviceOriginal,
		'search' => "if (!\$this->permissions->canViewTicket(\$ticket)) {\n\t\t\tthrow new CompanionNotFoundException();",
		'replace' => "if (false) {\n\t\t\tthrow new CompanionNotFoundException();",
	],
	[
		'label' => 'IDOR opacity: drop canEditTicket gate',
		'file' => $serviceTarget,
		'original' => $serviceOriginal,
		'search' => "if (!\$this->permissions->canEditTicket(\$ticket)) {\n\t\t\tthrow new CompanionNotFoundException();",
		'replace' => "if (false) {\n\t\t\tthrow new CompanionNotFoundException();",
	],
	[
		'label' => 'Transition deny bypass',
		'file' => $serviceTarget,
		'original' => $serviceOriginal,
		'search' => "if (!in_array(\$status, \$allowed, true)) {\n\t\t\tthrow new CompanionValidationException('STATUS_TRANSITION_DENIED'",
		'replace' => "if (false) {\n\t\t\tthrow new CompanionValidationException('STATUS_TRANSITION_DENIED'",
	],
	[
		'label' => 'Version CAS bypass',
		'file' => $serviceTarget,
		'original' => $serviceOriginal,
		'search' => 'if ($version !== $this->ticketVersion($ticket)) {',
		'replace' => 'if (false) {',
	],
	[
		'label' => 'Bulk cap off-by-one (rejects 25)',
		'file' => $serviceTarget,
		'original' => $serviceOriginal,
		'search' => 'if (count($ids) > self::BULK_MAX) {',
		'replace' => 'if (count($ids) >= self::BULK_MAX) {',
	],
	[
		'label' => 'Bulk cap removed',
		'file' => $serviceTarget,
		'original' => $serviceOriginal,
		'search' => 'if (count($ids) > self::BULK_MAX) {',
		'replace' => 'if (count($ids) > 10000) {',
	],
	[
		'label' => 'Overdue flag inverted for done tickets',
		'file' => $serviceTarget,
		'original' => $serviceOriginal,
		'search' => "if (!\$ticket->isOpen()) {\n\t\t\treturn false;",
		'replace' => "if (!\$ticket->isOpen()) {\n\t\t\treturn true;",
	],
	[
		'label' => 'Attachment viewer rule bypass',
		'file' => $serviceTarget,
		'original' => $serviceOriginal,
		'search' => "if (!\$this->ticketService->canViewerAccessAttachment(\$attachment, \$this->permissions->canViewInternalNotes())) {\n\t\t\tthrow new CompanionNotFoundException();",
		'replace' => "if (false) {\n\t\t\tthrow new CompanionNotFoundException();",
	],
	[
		'label' => 'Capability map loses attachments feature',
		'file' => $gateTarget,
		'original' => $gateOriginal,
		'search' => "'attachments',",
		'replace' => '',
	],
	[
		'label' => 'Guest role gate returns agent',
		'file' => $gateTarget,
		'original' => $gateOriginal,
		'search' => "if (!\$this->canAccessCompanion(\$uid)) {\n\t\t\treturn 'denied';",
		'replace' => "if (!\$this->canAccessCompanion(\$uid)) {\n\t\t\treturn 'agent';",
	],
];

$mwTarget = $appRoot . '/lib/Middleware/ClientLicenseMiddleware.php';
$mwOriginal = file_get_contents($mwTarget);
if ($mwOriginal === false) {
	fwrite(STDERR, "Cannot read middleware\n");
	exit(1);
}

$restoreAll = static function () use ($restore, $mwTarget, $mwOriginal): void {
	$restore();
	file_put_contents($mwTarget, $mwOriginal);
};

$mutations[] = [
	'label' => 'Stale session cookie shadows Basic identity (explicit credential not enforced)',
	'file' => $mwTarget,
	'original' => $mwOriginal,
	'search' => "\t\t\$this->enforceExplicitBasicIdentity();\n",
	'replace' => '',
];

$mutations[] = [
	'label' => 'Cookie companion auth allowed (seat CSRF bypass)',
	'file' => $mwTarget,
	'original' => $mwOriginal,
	'search' => "if (!\$this->usesAppPasswordAuth()) {\n\t\t\t// Cookie session alone (or forged Authorization without token login) must not hit companion.\n\t\t\tthrow new CompanionUnauthorizedException('NOT_AUTHENTICATED');\n\t\t}",
	'replace' => "if (false) {\n\t\t\tthrow new CompanionUnauthorizedException('NOT_AUTHENTICATED');\n\t\t}",
];

$mutations[] = [
	'label' => 'App password session proof removed',
	'file' => $mwTarget,
	'original' => $mwOriginal,
	'search' => "\$appPassword = \$this->session->get('app_password');\n\t\treturn is_string(\$appPassword) && \$appPassword !== '';",
	'replace' => "\$appPassword = \$this->session->get('app_password');\n\t\treturn true;",
];

$failures = 0;
foreach ($mutations as $mutation) {
	echo "== Mutant: {$mutation['label']} (must FAIL) ==\n";
	$mutated = str_replace($mutation['search'], $mutation['replace'], $mutation['original'], $count);
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

echo "All companion surface mutations killed.\n";
exit(0);
