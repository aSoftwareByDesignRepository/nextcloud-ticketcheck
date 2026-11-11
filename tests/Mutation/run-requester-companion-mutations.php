<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = $root . '/lib/Service/RequesterTicketService.php';
$original = (string)file_get_contents($source);
$target = tempnam(sys_get_temp_dir(), 'tc-native-mutant-');
$bootstrap = tempnam(sys_get_temp_dir(), 'tc-native-bootstrap-');
if ($target === false || $bootstrap === false) {
    throw new RuntimeException('Cannot allocate isolated mutation files');
}
register_shutdown_function(static function () use ($target, $bootstrap): void {
    unlink($target);
    unlink($bootstrap);
});
file_put_contents($bootstrap, '<?php require ' . var_export($root . '/tests/bootstrap.php', true)
    . '; require ' . var_export($target, true) . ';');

$run = static function (array $command): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot execute mutation test');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
};
$command = [PHP_BINARY, $root . '/vendor/bin/phpunit', '-c', $root . '/phpunit.xml',
    '--bootstrap', $bootstrap, $root . '/tests/Unit/Service/RequesterTicketServiceTest.php'];
file_put_contents($target, $original);
[$status, $output] = $run($command);
if ($status !== 0 || !str_contains($output, 'OK (21 tests,')) {
    fwrite(STDERR, "Baseline did not pass the complete focused suite:\n" . $output);
    exit(2);
}
$mutants = [
    ['revoked-role-admitted', 'if (!$this->gate->canAccessRequesterCompanion($uid))', 'if (false)'],
    ['invalid-cursor-admitted', "throw new CompanionValidationException('invalid_cursor', 'Invalid cursor');", 'return [];'],
    ['list-peer-leak', '&& $this->permissions->isRequesterScopedTicket($ticket, $uid)', '&& true'],
    ['list-merged-leak', '$ticket->getMergedIntoId() === null', 'true'],
    ['pagination-stops-at-filtered-page', '$examined < count($candidates) || count($candidates) === 100', '$examined < count($candidates)'],
    ['cursor-skips-unread-items', '(string)$tail : null', '(string)($tail - 1) : null'],
    ['internal-comments-leak', 'if ($comment->getIsInternal())', 'if (false)'],
    ['internal-attachments-leak', 'if (!$this->ticketService->canViewerAccessAttachment($attachment, false))', 'if (false)'],
    ['foreign-ticket-readable', 'if (!$this->permissions->isRequesterScopedTicket($ticket, $uid))', 'if (false)'],
    ['foreign-survivor-readable', 'if (!$this->permissions->isRequesterScopedTicket($active, $uid))', 'if (false)'],
    ['attachment-ticket-id-ignored', '(int)$attachment->getTicketId() !== (int)$ticket->getId()', 'false'],
    ['download-internal-file', '|| !$this->ticketService->canViewerAccessAttachment($attachment, false)', '|| false'],
];
$killed = 0;
foreach ($mutants as [$name, $from, $to]) {
    if (substr_count($original, $from) !== 1) throw new RuntimeException('Ambiguous mutation: ' . $name);
    file_put_contents($target, str_replace($from, $to, $original));
    [$syntax, $syntaxOutput] = $run([PHP_BINARY, '-l', $target]);
    if ($syntax !== 0) throw new RuntimeException('Invalid mutant: ' . $name . ': ' . $syntaxOutput);
    [$status, $output] = $run($command);
    if ($status === 1 && str_contains($output, 'FAILURES!') && !str_contains($output, 'Errors:')) {
        $killed++;
        echo 'KILLED ' . $name . PHP_EOL;
    } elseif ($status === 0) {
        echo 'SURVIVED ' . $name . PHP_EOL;
    } else {
        fwrite(STDERR, 'Invalid mutation run for ' . $name . PHP_EOL . $output);
        exit(2);
    }
}
echo 'Requester companion mutations: ' . $killed . '/' . count($mutants) . ' killed' . PHP_EOL;
exit($killed === count($mutants) ? 0 : 1);
