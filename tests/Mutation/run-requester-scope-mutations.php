<?php

declare(strict_types=1);

/**
 * Lightweight mutation harness for the internal-requester scope
 * (spec US-R05/NN-01/NN-02/D-R04/D-R15/D-R18).
 *
 * Every mutant must be killed by the requester-scope test files; a survivor
 * means the denial/isolation matrix has a hole.
 */
$root = dirname(__DIR__, 2);
$src = (string) file_get_contents($root . '/lib/Service/PermissionService.php');
$target = tempnam(sys_get_temp_dir(), 'tc-requester-mutant-');
$bootstrap = tempnam(sys_get_temp_dir(), 'tc-requester-bootstrap-');
if ($target === false || $bootstrap === false) {
	throw new \RuntimeException('Cannot allocate isolated mutation files');
}
register_shutdown_function(static function () use ($target, $bootstrap): void {
	unlink($target);
	unlink($bootstrap);
});
$bootstrapSource = '<?php require ' . var_export($target, true)
	. '; require ' . var_export($root . '/tests/bootstrap.php', true) . ';';
if (file_put_contents($target, $src) !== strlen($src)
	|| file_put_contents($bootstrap, $bootstrapSource) !== strlen($bootstrapSource)) {
	throw new \RuntimeException('Cannot initialize isolated mutation files');
}

$checks = [
	[
		'name' => 'isrequester-always-true',
		'from' => 'return $this->isRequesterGroupMember($userId);',
		'to' => 'return true;',
	],
	[
		'name' => 'guest-precedence-removed',
		'from' => 'if ($this->isGuest()) {
                return false;
            }
            // Staff scope wins — dual-role users keep the staff product.',
		'to' => 'if (false && $this->isGuest()) {
                return false;
            }
            // Staff scope wins — dual-role users keep the staff product.',
	],
	[
		'name' => 'staff-precedence-removed',
		'from' => 'if ($this->hasStaffScope($userId)) {
                return false;
            }
            return $this->isRequesterGroupMember($userId);',
		'to' => 'if (false && $this->hasStaffScope($userId)) {
                return false;
            }
            return $this->isRequesterGroupMember($userId);',
	],
	[
		'name' => 'master-toggle-ignored',
		'from' => 'if (empty($settings[\'allow_helpdesk_requesters\'])) {
            return false;
        }',
		'to' => 'if (false && empty($settings[\'allow_helpdesk_requesters\'])) {
            return false;
        }',
	],
	[
		'name' => 'failclosed-becomes-failopen',
		'from' => '            } catch (\Throwable $e) {
                $this->groupCache[$key] = false;',
		'to' => '            } catch (\Throwable $e) {
                $this->groupCache[$key] = true;',
	],
	[
		'name' => 'email-leg-restriction-removed',
		'from' => '        if (!$this->requesterEmailMatchAllowed($ticket, $userId)) {
            return false;
        }',
		'to' => '        if (false) {
            return false;
        }',
	],
	[
		'name' => 'd15-peer-author-allowed',
		'from' => '        // A uid authored this: allowed only when that uid holds staff scope.
        // Intentionally not hasEnabledHelpdeskScope() — requester scope must
        // not count, or two requesters sharing an address would leak (D-R15).
        // Fail-closed posture: a backend hiccup must not reopen the leak.
        return $this->hasStaffScope($createdBy, false);',
		'to' => '        return true;',
	],
	[
		'name' => 'door-auto-admit-removed',
		'from' => '        // Requester-group membership implies door admission (spec D-R08/D-R19):
        // a configured requester group must never hit a second allowlist.
        if ($this->isRequesterGroupMemberWithSettings($userId, $settings)) {
            return true;
        }',
		'to' => '        if (false && $this->isRequesterGroupMemberWithSettings($userId, $settings)) {
            return true;
        }',
	],
	[
		'name' => 'scope-branch-removed',
		'from' => '        // Requester scope (spec D-R20): checked before the functional-role
        // gate — requester membership IS the functional role here.
        if ($this->isRequesterGroupMemberWithSettings($userId, $settings)) {
            return true;
        }',
		'to' => '        if (false && $this->isRequesterGroupMemberWithSettings($userId, $settings)) {
            return true;
        }',
	],
];

$phpunit = $root . '/vendor/bin/phpunit';
$tests = [
	$root . '/tests/Unit/Service/PermissionServiceRequesterTest.php',
	$root . '/tests/Unit/Service/PermissionServiceRequesterScopeTest.php',
];
$killed = 0;
$total = count($checks);

require_once __DIR__ . '/MutationBaseline.php';
mutationBaselineSelfCheck(__FILE__);

foreach ($tests as $test) {
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($root . '/phpunit.xml') . ' --bootstrap ' . escapeshellarg($bootstrap) . ' ' . escapeshellarg($test) . ' 2>&1';
	exec($cmd, $baselineOutput, $baselineCode);
	if ($baselineCode !== 0) {
		fwrite(STDERR, "BASELINE-FAIL: {$test}\n" . implode("\n", $baselineOutput) . "\n");
		exit(1);
	}
	$baselineOutput = [];
}

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
	try {
		if (file_put_contents($target, $mutated) !== strlen($mutated)) {
			throw new \RuntimeException('Cannot write requester mutant');
		}
		foreach ($tests as $test) {
			$out = [];
			$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($root . '/phpunit.xml') . ' --bootstrap ' . escapeshellarg($bootstrap) . ' ' . escapeshellarg($test) . ' 2>&1';
			exec($cmd, $out, $code);
			if ($code !== 0) {
				break; // killed by this suite
			}
		}
	} finally {
		if (file_put_contents($target, $src) !== strlen($src)) {
			throw new \RuntimeException('Cannot restore requester source');
		}
	}
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED {$check['name']}\n" . implode("\n", $out) . "\n");
		exit(1);
	}
	$killed++;
	echo "Killed {$check['name']}\n";
}

echo "Requester-scope mutations: {$killed}/{$total} killed\n";
exit($killed === $total ? 0 : 1);
