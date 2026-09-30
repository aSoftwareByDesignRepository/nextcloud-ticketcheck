<?php

declare(strict_types=1);

/**
 * Mutation: portal download missing ticket must stay ticket_not_found (not ticket_or_attachment_not_found).
 * Inbound process failures must stay invalid_payload (not raw service errors).
 *
 *   php tests/Mutation/run-download-inbound-oracle-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$portal = $appRoot . '/lib/Controller/CustomerPortalController.php';
$inbound = $appRoot . '/lib/Controller/InboundEmailController.php';
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

function mutate_file(string $source, string $from, string $to): ?string {
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $from)) {
		return null;
	}
	$backup = $source . '.mutation-bak';
	file_put_contents($backup, $original);
	file_put_contents($source, str_replace($from, $to, $original));
	return $backup;
}

function restore(?string $backup, string $source): void {
	if ($backup !== null && is_file($backup)) {
		rename($backup, $source);
	}
}

echo "== baseline download + inbound reject oracle ==\n";
if (run_filter($appRoot, $phpunit, 'testDownloadAttachmentMissingTicketReturnsSameTicketNotFound|testWebhookReleasesReplayClaimWhenProcessFails') !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$failed = [];

$portalFrom = "        } catch (DoesNotExistException \$e) {\n            // Uniform with foreign-project deny — no missing-vs-forbidden body oracle.\n            return new JSONResponse(['error' => \$l->t('ticket_not_found')], 404);\n        }";
$portalTo = "        } catch (DoesNotExistException \$e) {\n            return new JSONResponse(['error' => \$l->t('ticket_or_attachment_not_found')], 404);\n        }";

echo "\n== mutation: portal_download_missing_uses_ticket_or_attachment_not_found ==\n";
$bak = mutate_file($portal, $portalFrom, $portalTo);
if ($bak === null) {
	$failed[] = 'portal_download_missing_uses_ticket_or_attachment_not_found (anchor missing)';
} else {
	try {
		$code = run_filter($appRoot, $phpunit, 'testDownloadAttachmentMissingTicketReturnsSameTicketNotFound');
		if ($code === 0) {
			$failed[] = 'portal_download_missing_uses_ticket_or_attachment_not_found';
			echo "MUTATION SURVIVED\n";
		} else {
			echo "killed\n";
		}
	} finally {
		restore($bak, $portal);
	}
}

$inboundFrom = "            return new JSONResponse([\n                'success' => false,\n                'error' => \$l->t('invalid_payload'),\n            ], 400);";
$inboundTo = "            return new JSONResponse([\n                'success' => false,\n                'error' => \$result['error'] ?? \$l->t('unknown_error'),\n            ], 400);";

echo "\n== mutation: inbound_echo_process_error ==\n";
$bak2 = mutate_file($inbound, $inboundFrom, $inboundTo);
if ($bak2 === null) {
	$failed[] = 'inbound_echo_process_error (anchor missing)';
} else {
	try {
		$code = run_filter($appRoot, $phpunit, 'testWebhookReleasesReplayClaimWhenProcessFails');
		if ($code === 0) {
			$failed[] = 'inbound_echo_process_error';
			echo "MUTATION SURVIVED\n";
		} else {
			echo "killed\n";
		}
	} finally {
		restore($bak2, $inbound);
	}
}

restore($bak ?? null, $portal);
restore($bak2 ?? null, $inbound);

if ($failed !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failed) . "\n");
	exit(1);
}
echo "\nAll download/inbound oracle mutations killed.\n";
exit(0);
