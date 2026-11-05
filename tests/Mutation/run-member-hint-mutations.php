<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: the member-availability hint must keep its audience
 * gates (manager explanation vs quiet non-manager hint), its admin-only
 * settings link, and its escaping — or the project members card becomes
 * a silent dead end / a dead link again.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-member-hint-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * @return list<array{file:string,from:string,to:string,label:string}>
 */
function member_hint_mutations(string $appRoot): array
{
	$partial = $appRoot . '/templates/parts/member-availability-hint.php';
	return [
		[
			'file' => $partial,
			'from' => '<?php if ($canManage): ?>',
			'to' => '<?php if (true): // mutated: non-manager hint branch unreachable ?>',
			'label' => 'member_hint_nonmanager_branch_removed',
		],
		[
			'file' => $partial,
			'from' => 'if ($isNcAdmin && isset($_[\'urlGenerator\']) && $_[\'urlGenerator\'] instanceof \OCP\IURLGenerator) {',
			'to' => 'if (isset($_[\'urlGenerator\']) && $_[\'urlGenerator\'] instanceof \OCP\IURLGenerator) { // mutated: link no longer admin-only',
			'label' => 'member_hint_settings_link_leaks_to_nonadmins',
		],
		[
			'file' => $partial,
			'from' => '<?php p($l->t(\'team_members_must_be_helpdesk_staff\')); ?>',
			'to' => '<?php print_unescaped($l->t(\'team_members_must_be_helpdesk_staff\')); // mutated: unescaped ?>',
			'label' => 'member_hint_staff_text_unescaped',
		],
		[
			'file' => $appRoot . '/templates/projects/detail.php',
			'from' => "include __DIR__ . '/../parts/member-availability-hint.php';",
			'to' => '// mutated: member availability hint include removed',
			'label' => 'member_hint_include_removed_from_detail',
		],
		[
			'file' => $appRoot . '/lib/Controller/ProjectController.php',
			'from' => "'isNcAdmin' => \$isNcAdmin,",
			'to' => "'isNcAdmin' => false, // mutated: admin detection defeated",
			'label' => 'member_hint_isncadmin_always_false',
		],
		[
			'file' => $appRoot . '/lib/Service/PermissionService.php',
			'from' => 'if ($this->isAppAdmin($userId)) {',
			'to' => 'if (false) { // mutated: app admins lose member management',
			'label' => 'member_admin_scope_appadmin_removed',
		],
		[
			'file' => $appRoot . '/lib/Service/PermissionService.php',
			'from' => 'return $this->isProjectAdmin($projectId, $userId);',
			'to' => 'return true; // mutated: every staff user may manage members',
			'label' => 'member_admin_scope_widened_to_all',
		],
		[
			'file' => $appRoot . '/lib/Service/PermissionService.php',
			'from' => 'if ($this->isAppAdmin($userId)) {',
			'to' => 'if ($this->isInGroupCached($userId, self::GROUP_HELPDESK_ADMINS)) { // mutated: dedicated app admins + NC admins dropped',
			'label' => 'member_admin_scope_narrows_to_group_only',
		],
		[
			'file' => $appRoot . '/lib/Service/PermissionService.php',
			'from' => 'if ($this->isGuest()) {
            return false;
        }

        // App admins',
			'to' => '// mutated: guest gate removed
        // App admins',
			'label' => 'member_admin_guest_gate_removed',
		],
		[
			'file' => $appRoot . '/lib/Service/ProjectMemberService.php',
			'from' => '        $this->assertProjectExists($projectId);',
			'to' => '        // mutated: phantom-project guard removed',
			'label' => 'member_add_phantom_project_guard_removed',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg('MemberAvailabilityHintRenderTest|PermissionServiceTest|ProjectMemberControllerTest|ProjectMemberServiceAddMemberTest');
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = member_hint_mutations($appRoot);
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
