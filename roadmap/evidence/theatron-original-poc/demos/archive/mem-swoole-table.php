#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;

exit(Archon::command('mem-swoole-table', static function (CommandContext $ctx): int {
    $table = new \OpenSwoole\Table(1024);
    $table->column('state', \OpenSwoole\Table::TYPE_STRING, 32);
    $table->column('detail', \OpenSwoole\Table::TYPE_STRING, 256);
    $table->column('updated_at', \OpenSwoole\Table::TYPE_FLOAT);
    $table->create();

    fprintf(STDERR, "PHP %s | OpenSwoole %s\n\n", PHP_VERSION, phpversion('openswoole'));

    fprintf(STDERR, "--- 1: Swoole Table set() same key x2000 ---\n");
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $table->set('row-1', [
            'state' => 'suspended',
            'detail' => '0.001s',
            'updated_at' => microtime(true),
        ]);
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 2: Swoole Table get() same key x2000 ---\n");
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $row = $table->get('row-1');
        unset($row);
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 3: Swoole Table set() alternating 4 keys x2000 ---\n");
    $keys = ['ann-1', 'ann-2', 'ann-3', 'ann-4'];
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        foreach ($keys as $key) {
            $table->set($key, [
                'state' => 'running',
                'detail' => '',
                'updated_at' => microtime(true),
            ]);
        }
    }
    fprintf(STDERR, "8000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 8000));

    fprintf(STDERR, "--- 4: pure array create + unset x2000 ---\n");
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $arr = [
            'state' => 'suspended',
            'detail' => '0.001s',
            'updated_at' => microtime(true),
        ];
        unset($arr);
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 5: microtime(true) x2000 ---\n");
    $base = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        $t = microtime(true);
        unset($t);
    }
    fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

    fprintf(STDERR, "--- 6: RuntimeMemory annotate() x2000 (via ledger) ---\n");
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
    $rc = new ReflectionClass($lifecycleScope);
    foreach ($rc->getProperties() as $prop) {
        $prop->setAccessible(true);
        if ($prop->getName() === 'supervisor') {
            $supervisor = $prop->getValue($lifecycleScope);
        }
    }
    if ($supervisor !== null) {
        $ledger = $supervisor->ledger;
        $memory = $ledger->memory;

        fprintf(STDERR, "--- 6a: resources->annotate() single key x2000 ---\n");
        $base = memory_get_usage();
        for ($i = 0; $i < 2000; $i++) {
            $memory->resources->annotate('run-000001', 'test.key', 'test-value');
        }
        fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

        fprintf(STDERR, "--- 6b: resources->recordEvent() x2000 ---\n");
        $base = memory_get_usage();
        for ($i = 0; $i < 2000; $i++) {
            $memory->resources->recordEvent('run-000001', 'test.event', 'a', 'b');
        }
        fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));

        fprintf(STDERR, "--- 6c: resources->get() x2000 ---\n");
        $base = memory_get_usage();
        for ($i = 0; $i < 2000; $i++) {
            $r = $memory->resources->get('run-000001');
            unset($r);
        }
        fprintf(STDERR, "2000 calls: %+dB (%+d/call)\n\n", memory_get_usage() - $base, (int)((memory_get_usage() - $base) / 2000));
    }

    return 0;
}, new CommandConfig())->default('mem-swoole-table')->run(array_slice($_SERVER['argv'] ?? [], 1)));
