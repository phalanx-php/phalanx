<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

use OpenSwoole\Coroutine;

/**
 * Typed wrapper around OpenSwoole's coroutine introspection surface.
 *
 * `OpenSwoole\Core\Coroutine\deadlock_check()` exists in the OpenSwoole
 * core library but only echoes a textual dump to stdout — fine for crash
 * reporting from a SIGUSR2 trap, useless for programmatic consumers.
 * This wrapper builds a structured report from the same primitives
 * (`Coroutine::list()`, `Coroutine::stats()`, `Coroutine::getBackTrace()`)
 * so an Archon `phalanx debug:deadlock` command (or any operator tooling)
 * can format the output however it wants.
 *
 * Aegis ships the type; Archon owns the actual command. The report is
 * collected with {@see collect()} and rendered with {@see format()}.
 */
final readonly class DeadlockReport
{
    /**
     * @param list<DeadlockFrame> $frames
     */
    public function __construct(
        public int $coroutineCount,
        public array $frames,
        public float $collectedAt,
    ) {
    }

    /**
     * Build a report from the live OpenSwoole runtime. Outside a coroutine
     * context the report is empty; the wrapper does not throw to keep the
     * Archon `debug:deadlock` command robust against being run pre-server.
     */
    public static function collect(int $maxFrames = 32, int $depth = 32): self
    {
        $stats = Coroutine::stats();
        $count = isset($stats['coroutine_num']) ? (int) $stats['coroutine_num'] : 0;
        $cids = Coroutine::list();

        $frames = [];
        $i = 0;
        foreach ($cids as $cid) {
            if ($i >= $maxFrames) {
                break;
            }
            $i++;
            $trace = Coroutine::getBackTrace((int) $cid, DEBUG_BACKTRACE_IGNORE_ARGS, $depth);
            $frames[] = new DeadlockFrame((int) $cid, self::renderTrace($trace));
        }

        return new self($count, $frames, microtime(true));
    }

    /**
     * @param list<DeadlockFrame> $frames
     */
    public static function fromFrames(int $coroutineCount, array $frames): self
    {
        return new self($coroutineCount, $frames, microtime(true));
    }

    /**
     * @param array<int, array<string, mixed>>|false $trace
     */
    private static function renderTrace(array|false $trace): string
    {
        if ($trace === false || $trace === []) {
            return '';
        }
        $lines = [];
        foreach ($trace as $depth => $frame) {
            $file = isset($frame['file']) ? (string) $frame['file'] : '<unknown>';
            $line = isset($frame['line']) ? (string) $frame['line'] : '?';
            $function = isset($frame['function']) ? (string) $frame['function'] : '<unknown>';
            $class = isset($frame['class']) ? (string) $frame['class'] . '::' : '';
            $lines[] = "#{$depth} {$class}{$function}() at {$file}:{$line}";
        }
        return implode(PHP_EOL, $lines);
    }

    public function format(): string
    {
        $lines = [
            '===================================================================',
            " [DEADLOCK REPORT]: {$this->coroutineCount} coroutines parked",
            '===================================================================',
        ];
        foreach ($this->frames as $frame) {
            $lines[] = '';
            $lines[] = " [Coroutine-{$frame->cid}]";
            $lines[] = '--------------------------------------------------------------------';
            $lines[] = $frame->backtrace;
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
