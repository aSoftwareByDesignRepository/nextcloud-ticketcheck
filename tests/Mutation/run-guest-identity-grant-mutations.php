<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the grant/revoke referent-resolution guards
 * (critic r26 / gp-grant-notfound-200): every mutation that lets a write
 * through for a missing identity or phantom project id, or corrupts the
 * `reused` flag, must be killed by GuestIdentityServiceGrantTest.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-guest-identity-grant-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * @return list<array{file:string,from:string,to:string,label:string}>
 */
function guest_identity_grant_mutations(string $appRoot): array
{
	$service = $appRoot . '/lib/Service/GuestIdentityService.php';
	return [
		[
			'file' => $service,
			'from' => '        $this->requireIdentity($identityId);' . "\n" . '        $this->assertProjectsExist($projectIds);',
			'to' => '        $this->assertProjectsExist($projectIds); // mutated: identity resolution dropped',
			'label' => 'grant_phantom_identity_guard_removed',
		],
		[
			'file' => $service,
			'from' => '        $this->requireIdentity($identityId);' . "\n" . '        $this->assertProjectsExist($projectIds);',
			'to' => '        $this->requireIdentity($identityId); // mutated: project existence check dropped',
			'label' => 'grant_phantom_project_guard_removed',
		],
		[
			'file' => $service,
			'from' => '    public function revokeProject(int $identityId, int $projectId, string $actor): void' . "\n"
				. '    {' . "\n"
				. '        $this->requireIdentity($identityId);' . "\n"
				. '        $this->assertProjectsExist([$projectId]);',
			'to' => '    public function revokeProject(int $identityId, int $projectId, string $actor): void' . "\n"
				. '    {' . "\n"
				. '        $this->assertProjectsExist([$projectId]); // mutated: identity resolution dropped',
			'label' => 'revoke_phantom_identity_guard_removed',
		],
		[
			'file' => $service,
			'from' => '    public function revokeProject(int $identityId, int $projectId, string $actor): void' . "\n"
				. '    {' . "\n"
				. '        $this->requireIdentity($identityId);' . "\n"
				. '        $this->assertProjectsExist([$projectId]);',
			'to' => '    public function revokeProject(int $identityId, int $projectId, string $actor): void' . "\n"
				. '    {' . "\n"
				. '        $this->requireIdentity($identityId); // mutated: project existence check dropped',
			'label' => 'revoke_phantom_project_guard_removed',
		],
		[
			'file' => $service,
			'from' => '            return [$existing, true];',
			'to' => '            return [$existing, false]; // mutated: reuse reported as fresh create',
			'label' => 'reused_flag_lies_on_existing',
		],
		[
			'file' => $service,
			'from' => '            return [$this->identities->insert($i), false];',
			'to' => '            return [$this->identities->insert($i), true]; // mutated: fresh insert reported as reuse',
			'label' => 'reused_flag_lies_on_insert',
		],
		[
			'file' => $service,
			'from' => '            $existing[(int)$row[\'id\']] = true;',
			'to' => '            $existing[0] = true; // mutated: every project reports missing',
			'label' => 'missing_project_lookup_defeated',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg('GuestIdentityServiceGrantTest');
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = guest_identity_grant_mutations($appRoot);
$failed = 0;
$killed = 0;

echo "Baseline…\n";
if (run_phpunit($phpunit, $appRoot) !== 0) {
	fwrite(STDERR, "Baseline failed — aborting mutations.\n");
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
	$code = run_phpunit($phpunit, $appRoot);
	file_put_contents($m['file'], $original);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$m['label']}\n");
		$failed++;
	} else {
		echo "Killed: {$m['label']}\n";
		$killed++;
	}
}

echo "Done: {$killed} killed, {$failed} survived/skipped.\n";
exit($failed === 0 ? 0 : 1);
