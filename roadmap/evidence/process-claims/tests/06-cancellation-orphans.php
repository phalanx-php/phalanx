<?php

declare(strict_types=1);

namespace Phalanx\Poc\ProcessClaims;

use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;
use Symfony\Component\Process\Process;

require __DIR__ . '/../bench.php';

Clock::start();
Logger::open(__DIR__ . '/../results/06-cancellation-orphans.txt');
Logger::header('Test 6: Cancellation — orphan grandchildren detection');

Logger::line('Claim: shell-wrapped commands that fork a backgrounded child can leave the');
Logger::line('  child orphaned on SIGTERM because the signal goes to the shell, not the');
Logger::line('  whole job. Fixes: prefix the command with `exec` so shell IS the child,');
Logger::line('  use argv form (no shell), or signal the entire process group.');
Logger::line('');
Logger::line('Method: spawn each variant, snapshot children of the spawned PID, then');
Logger::line('  stop(). Recheck the snapshot — anyone still alive is an orphan.');
Logger::line('');

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$descendantsOf = static function (int $pid): array {
    $out = (string) shell_exec("ps -A -o pid=,ppid= 2>/dev/null");
    $children = [];
    foreach (preg_split('/\R/', $out) ?: [] as $row) {
        $row = trim($row);
        if ($row === '') {
            continue;
        }
        $parts = preg_split('/\s+/', $row);
        if ($parts === false || count($parts) < 2) {
            continue;
        }
        if ((int) $parts[1] === $pid) {
            $children[] = (int) $parts[0];
        }
    }
    return $children;
};

$pidAlive = static function (int $pid): bool {
    $out = (string) shell_exec("ps -p {$pid} -o pid= 2>/dev/null");
    return trim($out) !== '';
};

$run = static function (string $label, Process $proc) use ($descendantsOf, $pidAlive): void {
    Logger::line(sprintf('--- %s', $label));
    Logger::line('  command: ' . $proc->getCommandLine());
    $proc->start();
    $pid = $proc->getPid();
    Logger::line(sprintf('  spawned pid=%d', $pid ?? -1));

    if ($pid === null) {
        Logger::line('  could not get pid; skipping');
        return;
    }

    usleep(250_000);

    $children = $descendantsOf($pid);
    Logger::line(sprintf('  children of %d: [%s]', $pid, implode(', ', $children)));

    $proc->stop(1.0, SIGTERM);
    usleep(400_000);

    $stillAlive = [];
    foreach ($children as $childPid) {
        if ($pidAlive($childPid)) {
            $stillAlive[] = $childPid;
        }
    }
    $exit = $proc->getExitCode();
    Logger::line(sprintf(
        '  after stop(): exit=%s, surviving descendants=[%s]',
        $exit === null ? 'null' : (string) $exit,
        implode(', ', $stillAlive),
    ));

    if (count($stillAlive) > 0) {
        Logger::line('  ORPHANS DETECTED — killing them now');
        foreach ($stillAlive as $orphan) {
            posix_kill($orphan, SIGKILL);
        }
        usleep(100_000);
    }
    Logger::line('');
};

Coroutine::run(static function () use ($run): void {
    Coroutine::create(static function () use ($run): void {
        $run(
            'A: shell with backgrounded child (`sleep 30 &; wait`)',
            Process::fromShellCommandline('sleep 30 & PID=$!; wait $PID'),
        );

        $run(
            'B: shell with `exec` prefix (`exec sleep 30`)',
            Process::fromShellCommandline('exec sleep 30'),
        );

        $run(
            'C: argv form (no shell) — Symfony default',
            new Process(['sleep', '30']),
        );

        $run(
            'D: shell job started in its own pgid (POSIX setpgid via setsid)',
            Process::fromShellCommandline('setsid sleep 30 & PID=$!; wait $PID'),
        );
    });
});
