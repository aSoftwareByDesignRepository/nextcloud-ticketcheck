<?php

declare(strict_types=1);

/**
 * Lightweight mutation harness for TicketCheck access-door OR-semantics.
 */
$root = dirname(__DIR__, 2);
$target = $root . '/lib/Service/PermissionService.php';
$src = (string) file_get_contents($target);

$checks = [
	[
		'name' => 'collapse-open-to-require-role',
		'from' => "// Open mode: door ≠ role. Anyone logged in may enter; pages/APIs still\n        // require an enabled helpdesk scope (or show the calm enrollment page).\n        return true;",
		'to' => "// mutated open mode\n        return \$this->hasEnabledHelpdeskScope(\$userId, \$settings);",
	],
	[
		'name' => 'remove-dedicated-app-admin-or',
		'from' => 'return in_array($userId, $this->getAppAdminUserIds(), true);',
		'to' => 'return false;',
	],
	[
		'name' => 'guest-carve-out-always-false',
		'from' => 'if (!empty($settings[\'allow_helpdesk_customers\'])
            && $this->isInGroupCached($userId, self::GROUP_HELPDESK_CUSTOMERS)) {
            return true;
        }',
		'to' => 'if (false && !empty($settings[\'allow_helpdesk_customers\'])
            && $this->isInGroupCached($userId, self::GROUP_HELPDESK_CUSTOMERS)) {
            return true;
        }',
	],
];

$phpunit = $root . '/vendor/bin/phpunit';
$test = $root . '/tests/Unit/Service/PermissionServiceAccessDoorTest.php';
$killed = 0;
$total = count($checks);

foreach ($checks as $check) {
	if (!str_contains($src, $check['from'])) {
		fwrite(STDERR, "SKIP {$check['name']}: pattern not found\n");
		exit(1);
	}
	$mutated = str_replace($check['from'], $check['to'], $src, $count);
	if ($count !== 1) {
		fwrite(STDERR, "FAIL {$check['name']}: expected 1 replacement, got {$count}\n");
		exit(1);
	}
	file_put_contents($target, $mutated);
	$out = [];
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($root . '/phpunit.xml') . ' ' . escapeshellarg($test) . ' 2>&1';
	exec($cmd, $out, $code);
	file_put_contents($target, $src);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED {$check['name']}\n" . implode("\n", $out) . "\n");
		exit(1);
	}
	$killed++;
	echo "Killed {$check['name']}\n";
}

echo "Access-door mutations: {$killed}/{$total} killed\n";
exit($killed === $total ? 0 : 1);
