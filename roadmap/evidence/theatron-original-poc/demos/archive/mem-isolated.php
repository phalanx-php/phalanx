#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;

exit(Archon::command('mem-isolated', static function (CommandContext $ctx): int {
    $unwrap = static function (object $obj): object {
        $rc = new ReflectionClass($obj);
        foreach ($rc->getProperties() as $prop) {
            if ($prop->getName() === 'inner') {
                $prop->setAccessible(true);
                return $prop->getValue($obj);
            }
        }
        return $obj;
    };
    $lifecycleScope = $unwrap($ctx);

    $supervisor = null;
    $currentRun = null;
    $rc = new ReflectionClass($lifecycleScope);
    foreach ($rc->getProperties() as $prop) {
        $prop->setAccessible(true);
        if ($prop->getName() === 'supervisor') {
            $supervisor = $prop->getValue($lifecycleScope);
        }
        if ($prop->getName() === 'currentRun') {
            $currentRun = $prop->getValue($lifecycleScope);
        }
    }

    if ($supervisor === null || $currentRun === null) {
        fprintf(STDERR, "Failed to extract supervisor/currentRun\n");
        return 1;
    }

    $ledger = $supervisor->ledger;
    $runId = $currentRun->id;
    $reason = \Phalanx\Supervisor\WaitReason::delay(0.001);

    fprintf(STDERR, "PHP %s\n\n", PHP_VERSION);

    fprintf(STDERR, "--- 1: ledger->find() x2000 ---\n");
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $ledger->find($runId);
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 2: ledger->clearWait() no-op x2000 ---\n");
    $ledger->clearWait($runId);
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $ledger->clearWait($runId);
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 3: ledger->beginWait() reused reason x2000 ---\n");
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $ledger->beginWait($runId, $reason);
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 4: beginWait + clearWait reused reason x2000 ---\n");
    $ledger->clearWait($runId);
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $ledger->beginWait($runId, $reason);
        $ledger->clearWait($runId);
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 5: direct property toggle x2000 ---\n");
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $currentRun->currentWait = $reason;
        $currentRun->state = \Phalanx\Supervisor\RunState::Suspended;
        $currentRun->currentWait = null;
        $currentRun->state = \Phalanx\Supervisor\RunState::Running;
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 6: plain array lookup by string key x2000 ---\n");
    $arr = [$runId => $currentRun];
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $found = $arr[$runId] ?? null;
        unset($found);
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 7: inline ledger logic (no method call) x2000 ---\n");
    $runsRef = (new ReflectionProperty($ledger, 'runs'));
    $runsRef->setAccessible(true);
    $runs = &$runsRef->getValue($ledger);
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $run = $runs[$runId] ?? null;
        if ($run !== null) {
            $run->state = \Phalanx\Supervisor\RunState::Suspended;
            $run->currentWait = $reason;
        }
        $run = $runs[$runId] ?? null;
        if ($run !== null && $run->state === \Phalanx\Supervisor\RunState::Suspended) {
            $run->state = \Phalanx\Supervisor\RunState::Running;
            $run->currentWait = null;
        }
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 8: gc_collect_cycles after tests ---\n");
    $before = memory_get_usage();
    gc_collect_cycles();
    gc_mem_caches();
    $after = memory_get_usage();
    fprintf(STDERR, "Reclaimed: %+dB\n\n", $after - $before);

    return 0;
}, new CommandConfig())->default('mem-isolated')->run(array_slice($_SERVER['argv'] ?? [], 1)));
