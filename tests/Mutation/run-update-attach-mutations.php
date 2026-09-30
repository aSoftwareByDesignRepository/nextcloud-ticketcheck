<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for staff updatePost attach-first atomicity.
 *
 * Baseline TicketControllerUpdateAttachAtomicityTest must pass; then known-bad
 * controller mutations must be killed (proves tests catch broken ordering).
 *
 * Usage (Docker):
 *   docker compose exec nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-update-attach-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$source = $appRoot . '/lib/Controller/TicketController.php';
$backup = $source . '.mutation-bak';
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

function run_update_attach_tests(string $appRoot, string $phpunit): int
{
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter "TicketControllerUpdateAttachAtomicityTest|TicketControllerCreateAttachCompensationTest"';
	passthru($cmd, $code);
	return (int) $code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

if (!is_file($source)) {
	fwrite(STDERR, "Missing TicketController.php\n");
	exit(1);
}

echo "== baseline TicketControllerUpdateAttachAtomicityTest ==\n";
$baseline = run_update_attach_tests($appRoot, $phpunit);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$mutations = [
	// Revert to update-then-attach: fields mutate before upload failure.
	'update_before_attach' => [
		'from' => "            // Attach-first when this request includes files: never mutate ticket\n            // fields if the attach batch fails (create-style all-or-nothing UX).\n            // Partial attaches are compensated inside handleMultipleFileUploads.\n            // If field update fails after a successful batch, compensate those\n            // new attachment ids so the ticket is left unchanged.\n            \$uploadedFiles = [];\n            try {\n                if (\$pendingAttachmentCount > 0) {\n                    \$uploadedFiles = \$this->handleMultipleFileUploads(\n                        \$activeId,\n                        \$pendingAttachmentCount\n                    );\n                }\n\n                \$updatedTicket = \$this->ticketService->updateTicket(\n                    \$activeId,\n                    \$data,\n                    \$this->buildTicketUpdateAuthzAssert(\$projectId)\n                );\n            } catch (\\InvalidArgumentException \$e) {\n                return new JSONResponse([\n                    'success' => false,\n                    'message' => \$l->t('file_upload_failed_try_again'),\n                    'ticket_id' => \$activeId,\n                ], 400);\n            } catch (AppAccessDeniedException \$e) {\n                \$this->compensateUploadedAttachments(\$activeId, \$uploadedFiles);\n                throw \$e;\n            } catch (\\Throwable \$e) {\n                \$this->compensateUploadedAttachments(\$activeId, \$uploadedFiles);\n                throw \$e;\n            }",
		'to' => "            \$updatedTicket = \$this->ticketService->updateTicket(\n                \$activeId,\n                \$data,\n                \$this->buildTicketUpdateAuthzAssert(\$projectId)\n            );\n            \$uploadedFiles = [];\n            if (\$pendingAttachmentCount > 0) {\n                try {\n                    \$uploadedFiles = \$this->handleMultipleFileUploads(\n                        (int) \$updatedTicket->getId(),\n                        \$pendingAttachmentCount\n                    );\n                } catch (\\InvalidArgumentException \$e) {\n                    return new JSONResponse([\n                        'success' => false,\n                        'message' => \$l->t('file_upload_failed_try_again'),\n                        'ticket_id' => \$updatedTicket->getId(),\n                    ], 400);\n                }\n            }",
	],
	'skip_batch_compensate_on_upload_fail' => [
		'from' => "        } catch (\\Throwable \$e) {\n            // Match portal: compensate on any failure mode (Error included), not only\n            // InvalidArgumentException / AppAccessDeniedException.\n            \$this->compensateUploadedAttachments(\$ticketId, \$uploadedFiles);\n            throw \$e;\n        }",
		'to' => "        } catch (\\InvalidArgumentException \$e) {\n            throw \$e;\n        }",
	],
	'narrow_batch_compensate_to_invalid_argument_only' => [
		'from' => "        } catch (\\Throwable \$e) {\n            // Match portal: compensate on any failure mode (Error included), not only\n            // InvalidArgumentException / AppAccessDeniedException.\n            \$this->compensateUploadedAttachments(\$ticketId, \$uploadedFiles);\n            throw \$e;\n        }",
		'to' => "        } catch (\\InvalidArgumentException \$e) {\n            \$this->compensateUploadedAttachments(\$ticketId, \$uploadedFiles);\n            throw \$e;\n        }",
	],
];

$failedToKill = [];
foreach ($mutations as $name => $pair) {
	echo "\n== mutation: {$name} ==\n";
	$original = file_get_contents($source);
	if ($original === false) {
		fwrite(STDERR, "Cannot read source\n");
		exit(1);
	}
	if (!str_contains($original, $pair['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		$failedToKill[] = $name . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($pair['from'], $pair['to'], $original);
	if ($mutated === $original) {
		fwrite(STDERR, "Mutation replace had no effect for {$name}\n");
		$failedToKill[] = $name . ' (no effect)';
		restore($source, $backup);
		continue;
	}
	if (file_put_contents($source, $mutated) === false) {
		fwrite(STDERR, "Cannot write mutated source for {$name}\n");
		$failedToKill[] = $name . ' (write failed)';
		restore($source, $backup);
		continue;
	}
	$code = run_update_attach_tests($appRoot, $phpunit);
	restore($source, $backup);
	if ($code === 0) {
		$failedToKill[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

restore($source, $backup);

if ($failedToKill !== []) {
	fwrite(STDERR, "Mutations not killed: " . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll updatePost attach-atomicity mutations killed.\n";
exit(0);
