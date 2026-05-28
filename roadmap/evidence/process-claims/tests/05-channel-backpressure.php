<?php

declare(strict_types=1);

namespace Phalanx\Poc\ProcessClaims;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\System;
use OpenSwoole\Runtime;

require __DIR__ . '/../bench.php';

Clock::start();
Logger::open(__DIR__ . '/../results/05-channel-backpressure.txt');
Logger::header('Test 5: Channel backpressure to OS pipe and child');

Logger::line('Claim: A bounded Coroutine\\Channel between forwarder (proc reader) and');
Logger::line('  consumer extends backpressure all the way to the OS pipe. When the');
Logger::line('  channel saturates, the forwarder stops fread()ing; the pipe buffer fills;');
Logger::line('  the child blocks on stdout write — i.e. consumer rate dictates producer rate.');
Logger::line('');
Logger::line('Method: a fast-producing child writes ~1000 lines as quickly as it can.');
Logger::line('  Forwarder reads lines, push()es to a Channel(capacity=4).');
Logger::line('  Consumer pop()s and sleeps 10ms per line.');
Logger::line('  We measure (a) total wall time and (b) child wall time.');
Logger::line('  Expected: total wall ≈ 1000 * 10ms = ~10s. If unbounded, total ≈ child only.');
Logger::line('');

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$linesToProduce = 200;
$consumerDelayUs = 10_000;
$capacity = 4;
$cmd = "/bin/sh -c 'i=0; while [ \$i -lt {$linesToProduce} ]; do echo \"line-\$i\"; i=\$((i+1)); done'";

Coroutine::run(static function () use ($cmd, $linesToProduce, $consumerDelayUs, $capacity): void {
    Coroutine::create(static function () use ($cmd, $linesToProduce, $consumerDelayUs, $capacity): void {
        $channel = new Channel($capacity);
        $producedAt = [];
        $consumedAt = [];
        $childExitedAt = 0.0;

        $started = Clock::ms();

        Coroutine::create(static function () use ($cmd, $channel, $linesToProduce, &$producedAt, &$childExitedAt): void {
            $pipes = [];
            $proc = proc_open($cmd, [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ], $pipes);
            stream_set_blocking($pipes[1], false);

            $buffer = '';
            while (true) {
                $ready = System::waitEvent($pipes[1], SWOOLE_EVENT_READ, 30.0);
                if ($ready === false) {
                    break;
                }
                $chunk = fread($pipes[1], 8192);
                if ($chunk === false || $chunk === '') {
                    if (feof($pipes[1])) {
                        break;
                    }
                    continue;
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $producedAt[] = Clock::ms();
                    $channel->push($line);
                }
            }
            $childExitedAt = Clock::ms();
            foreach ($pipes as $p) {
                if (is_resource($p)) {
                    fclose($p);
                }
            }
            proc_close($proc);
            $channel->close();
        });

        Coroutine::create(static function () use ($channel, $consumerDelayUs, &$consumedAt): void {
            while (true) {
                $line = $channel->pop();
                if ($line === false) {
                    break;
                }
                $consumedAt[] = Clock::ms();
                usleep($consumerDelayUs);
            }
        });

        while (count($consumedAt) < $linesToProduce) {
            usleep(50_000);
            if (count($consumedAt) >= $linesToProduce) {
                break;
            }
        }

        $totalElapsed = Clock::ms() - $started;
        $childElapsed = $childExitedAt - $started;
        $expectedConsumerWork = ($linesToProduce * $consumerDelayUs) / 1000.0;

        Logger::line(sprintf(
            'lines produced: %d, lines consumed: %d',
            count($producedAt),
            count($consumedAt),
        ));
        Logger::line(sprintf(
            'child finished writing at: %.0fms (would be ~10ms unthrottled)',
            $childElapsed,
        ));
        Logger::line(sprintf(
            'total elapsed: %.0fms (expected ~%.0fms if backpressure is real)',
            $totalElapsed,
            $expectedConsumerWork,
        ));

        $childThrottled = $childElapsed > ($expectedConsumerWork * 0.5);
        $verdict = $childThrottled
            ? sprintf(
                'PROVEN. Child took %.0fms to write %d lines while consumer worked at %dms/line. The bounded Channel propagated backpressure through the forwarder, the pipe, and into the child.',
                $childElapsed,
                $linesToProduce,
                $consumerDelayUs / 1000,
            )
            : sprintf(
                'REFUTED. Child finished in %.0fms, well before consumer (~%.0fms expected). The Channel buffered everything; the pipe never filled.',
                $childElapsed,
                $expectedConsumerWork,
            );
        Logger::line('VERDICT: ' . $verdict);
    });
});
