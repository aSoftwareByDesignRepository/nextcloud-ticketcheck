<?php

declare(strict_types=1);

/**
 * Mutation: removeLinkForTicket must authz before linkMapper->find.
 *
 *   php tests/Mutation/run-remove-link-oracle-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$source = $appRoot . '/lib/Service/TicketLinkService.php';
$backup = $source . '.mutation-bak';
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

function run_filter(string $appRoot, string $phpunit, string $filter): int {
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

echo "== baseline remove-link authz-first ==\n";
if (run_filter($appRoot, $phpunit, 'TicketLinkServiceRemoveScopedTest') !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$failed = [];

$from = "    public function removeLinkForTicket(int \$ticketId, int \$linkId): void\n    {\n        // Authz before link lookup — foreign callers must not probe link ids.\n        try {\n            \$ticket = \$this->ticketMapper->find(\$ticketId);\n        } catch (DoesNotExistException) {\n            throw new \\InvalidArgumentException('ticket_not_found');\n        }\n        if (!\$this->permissionService->canEditTicket(\$ticket)) {\n            throw new \\InvalidArgumentException('ticket_not_found');\n        }\n\n        try {\n            \$link = \$this->linkMapper->find(\$linkId);\n        } catch (DoesNotExistException) {\n            throw new \\InvalidArgumentException('Link does not belong to this ticket');\n        }";

$to = "    public function removeLinkForTicket(int \$ticketId, int \$linkId): void\n    {\n        \$link = \$this->linkMapper->find(\$linkId);\n        try {\n            \$ticket = \$this->ticketMapper->find(\$ticketId);\n        } catch (DoesNotExistException) {\n            throw new \\InvalidArgumentException('ticket_not_found');\n        }\n        if (!\$this->permissionService->canEditTicket(\$ticket)) {\n            throw new \\InvalidArgumentException('ticket_not_found');\n        }\n        if (false) {\n            throw new \\InvalidArgumentException('Link does not belong to this ticket');\n        }";

echo "\n== mutation: link_find_before_authz ==\n";
$original = file_get_contents($source);
if ($original === false || !str_contains($original, $from)) {
	$failed[] = 'link_find_before_authz (anchor missing)';
} else {
	file_put_contents($backup, $original);
	try {
		file_put_contents($source, str_replace($from, $to, $original));
		if (run_filter($appRoot, $phpunit, 'TicketLinkServiceRemoveScopedTest::testRemoveLinkForTicketDoesNotProbeLinkWhenCannotEdit') === 0) {
			$failed[] = 'link_find_before_authz';
			echo "MUTATION SURVIVED\n";
		} else {
			echo "killed link_find_before_authz\n";
		}
	} finally {
		restore($source, $backup);
	}
}

$pairFrom = "        // Pre-lock authz (addLink-style): missing ≡ foreign → ticket_not_found; no lock burn.\n        try {\n            \$ticket = \$this->ticketMapper->find(\$ticketId);\n            \$linked = \$this->ticketMapper->find(\$linkedTicketId);\n        } catch (DoesNotExistException) {\n            throw new \\InvalidArgumentException('ticket_not_found');\n        }\n        if (!\$this->permissionService->canEditTicket(\$ticket)\n            || !\$this->permissionService->canEditTicket(\$linked)) {\n            throw new \\InvalidArgumentException('ticket_not_found');\n        }\n\n        \$storeTicketId = \$ticketId;";
$pairTo = "        \$storeTicketId = \$ticketId;";

echo "\n== mutation: drop_removeLinkByPair_prelock_authz ==\n";
$original = file_get_contents($source);
if ($original === false || !str_contains($original, $pairFrom)) {
	$failed[] = 'drop_removeLinkByPair_prelock_authz (anchor missing)';
} else {
	file_put_contents($backup, $original);
	try {
		file_put_contents($source, str_replace($pairFrom, $pairTo, $original));
		if (run_filter($appRoot, $phpunit, 'TicketLinkServiceRemoveScopedTest::testRemoveLinkByPairDoesNotLockWhenCannotEditLinked|TicketLinkServiceRemoveScopedTest::testRemoveLinkByPairMissingLinkedIsTicketNotFound') === 0) {
			$failed[] = 'drop_removeLinkByPair_prelock_authz';
			echo "MUTATION SURVIVED\n";
		} else {
			echo "killed drop_removeLinkByPair_prelock_authz\n";
		}
	} finally {
		restore($source, $backup);
	}
}

restore($source, $backup);
if ($failed !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failed) . "\n");
	exit(1);
}
echo "\nAll remove-link oracle mutations killed.\n";
exit(0);

