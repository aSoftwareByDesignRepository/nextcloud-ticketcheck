<?php

declare(strict_types=1);

/**
 * Mutation: portal create/reply must keep Throwable compensate for orphan ticket/comment.
 *
 *   php tests/Mutation/run-portal-attach-orphan-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$source = $appRoot . '/lib/Controller/CustomerPortalController.php';
$backup = $source . '.mutation-bak';
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

function run_tests(string $appRoot, string $phpunit): int {
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter CustomerPortalControllerAttachErrorCompensationTest';
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

echo "== baseline portal attach Error compensation ==\n";
if (run_tests($appRoot, $phpunit) !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$mutations = [
	'narrow_create_attach_compensate_to_invalid_argument' => [
		'from' => "                        } catch (\\Throwable \$e) {\n                            // Compensate: never leave an orphan ticket after a failed attach batch\n                            // (covers InvalidArgument, ticket_not_found, and Error).\n                            try {\n                                \$this->ticketService->deleteTicket((int) \$created->getId());\n                            } catch (\\Throwable \$cleanup) {\n                                \$this->logger->error('Portal create attach failed; ticket compensation delete failed', [\n                                    'ticket_id' => \$created->getId(),\n                                    'exception' => \$cleanup,\n                                ]);\n                            }\n                            throw \$e;\n                        }",
		'to' => "                        } catch (\\InvalidArgumentException \$e) {\n                            try {\n                                \$this->ticketService->deleteTicket((int) \$created->getId());\n                            } catch (\\Throwable \$cleanup) {\n                            }\n                            throw \$e;\n                        }",
	],
	'narrow_reply_attach_compensate_to_invalid_argument' => [
		'from' => "                        } catch (\\Throwable \$e) {\n                            // Compensate: never leave an orphan reply after a failed attach batch.\n                            try {\n                                \$this->ticketService->deleteComment(\n                                    \$activeId,\n                                    (int) \$commentObj->getId()\n                                );\n                            } catch (\\Throwable \$cleanup) {\n                                \$this->logger->error('Portal reply attach failed; comment compensation delete failed', [\n                                    'ticket_id' => \$activeId,\n                                    'comment_id' => \$commentObj->getId(),\n                                    'exception' => \$cleanup,\n                                ]);\n                            }\n                            throw \$e;\n                        }",
		'to' => "                        } catch (\\InvalidArgumentException \$e) {\n                            try {\n                                \$this->ticketService->deleteComment(\n                                    \$activeId,\n                                    (int) \$commentObj->getId()\n                                );\n                            } catch (\\Throwable \$cleanup) {\n                            }\n                            throw \$e;\n                        }",
	],
];

$failed = [];
foreach ($mutations as $name => $pair) {
	echo "\n== mutation: {$name} ==\n";
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $pair['from'])) {
		$failed[] = $name . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	try {
		file_put_contents($source, str_replace($pair['from'], $pair['to'], $original));
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

restore($source, $backup);
if ($failed !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failed) . "\n");
	exit(1);
}
echo "\nAll portal attach-orphan mutations killed.\n";
exit(0);
