<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use OpenSwoole\Coroutine\System;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;
use Phalanx\System\SystemCommand;
use RuntimeException;

/**
 * Aegis-managed console input capability.
 *
 * Reads from a stdin-shaped resource in a coroutine-aware way without
 * requiring SWOOLE_HOOK_STDIO to be enabled globally. The read path is:
 *
 *   1. Yield via OpenSwoole\Coroutine\System::waitEvent($fd, READ, $timeout)
 *      under $scope->call(..., WaitReason::input(...)) so the supervisor
 *      sees a typed "input" wait and cancellation propagates.
 *   2. Once readable, call non-blocking fread() to drain available bytes.
 *
 * Lifecycle: callers who switch the terminal to raw mode via
 * enableRawMode() must ensure restore() runs on scope teardown. Register
 * the restore via $scope->onDispose(...) at the call site, or use the
 * ConsoleInputServiceBundle which wires it as a singleton onShutdown.
 *
 * Non-TTY behaviour: when $resource is not a tty (piped input, redirected
 * file), enableRawMode() throws NonInteractiveTtyException. read() still
 * works on non-tty streams — pipes deliver bytes the same way, and the
 * supervisor wait/cancel semantics are identical.
 */
final class ConsoleInput
{
    public bool $isInteractive {
        get => $this->interactive;
    }

    /** @var resource */
    private $resource;

    private readonly int $fd;

    private readonly bool $interactive;

    private bool $rawModeActive = false;

    private ?string $savedSttyState = null;

    /** @param resource|null $resource */
    public function __construct($resource = null)
    {
        $this->resource = $resource ?? STDIN;
        $fd = (int) $this->resource;
        $this->fd = $fd;
        $this->interactive = stream_isatty($this->resource);

        // Set the resource non-blocking so fread() never blocks the worker
        // when waitEvent has already returned readiness. Best-effort: some
        // wrappers (php://memory, php://temp) silently ignore this.
        @stream_set_blocking($this->resource, false);
    }

    public function read(Suspendable $scope, int $bytes = 1024, ?float $timeout = null): string
    {
        if ($bytes < 1) {
            return '';
        }

        $resource = $this->resource;
        $fd = $this->fd;
        $waitTimeout = $timeout ?? -1.0;

        $ready = $scope->call(
            static fn(): bool|int => System::waitEvent($fd, SWOOLE_EVENT_READ, $waitTimeout),
            WaitReason::input(),
        );

        if ($ready === false) {
            return '';
        }

        $chunk = fread($resource, $bytes);
        return $chunk === false ? '' : $chunk;
    }

    /**
     * Read one line ("\n"-terminated) from the input stream.
     *
     * Note: $timeout applies to the wait-for-readiness on each individual
     * byte, not to the line as a whole. A user typing slowly with a 1s
     * timeout can produce a line that takes minutes — each byte just has
     * to arrive within 1s of the previous one. For total-line timeouts,
     * wrap the call in $scope->timeout(seconds, ...).
     */
    public function readLine(Suspendable $scope, ?float $timeout = null): string
    {
        $buffer = '';
        while (true) {
            $chunk = $this->read($scope, 1, $timeout);
            if ($chunk === '') {
                return $buffer;
            }
            $buffer .= $chunk;
            if ($chunk === "\n") {
                return $buffer;
            }
        }
    }

    public function enableRawMode(Suspendable $scope): void
    {
        if ($this->rawModeActive) {
            return;
        }
        if (!$this->interactive) {
            throw new NonInteractiveTtyException(
                'Cannot enable raw mode: input stream is not a TTY.',
            );
        }

        $current = (new SystemCommand('stty -g'))($scope);
        $current->throwIfFailed('snapshot stty state');
        $this->savedSttyState = trim($current->output);

        $enable = (new SystemCommand('stty -icanon -echo min 1 time 0'))($scope);
        $enable->throwIfFailed('enable raw stty mode');
        $this->rawModeActive = true;
    }

    public function restore(Suspendable $scope): void
    {
        if (!$this->rawModeActive || $this->savedSttyState === null) {
            return;
        }

        $savedState = $this->savedSttyState;
        // Clear state up-front so a failing stty doesn't leave the object
        // in a "still raw, can't retry" zombie. Caller can re-enableRawMode
        // afresh if they need to recover.
        $this->rawModeActive = false;
        $this->savedSttyState = null;

        $result = SystemCommand::from('stty', $savedState)($scope);
        if (!$result->successful) {
            throw new RuntimeException(
                "Failed to restore stty state: {$result->output}",
            );
        }
    }
}
