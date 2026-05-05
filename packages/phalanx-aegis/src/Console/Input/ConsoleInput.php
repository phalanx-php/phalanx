<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use OpenSwoole\Coroutine\Socket;
use OpenSwoole\Coroutine\System;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;
use Phalanx\System\SystemCommand;
use RuntimeException;

/**
 * Aegis-managed console input capability.
 *
 * Reads from a kernel-tracked input source in a coroutine-aware way
 * without requiring SWOOLE_HOOK_STDIO to be enabled globally. The
 * source can be either:
 *   - a PHP stream resource backed by a real fd (STDIN, proc_open
 *     pipes, file streams). The read path yields via
 *     OpenSwoole\Coroutine\System::waitEvent($resource, READ, $timeout)
 *     and drains via non-blocking fread().
 *   - an OpenSwoole\Coroutine\Socket. The read path yields via
 *     $socket->recv($bytes, $timeout), which is reactor-native.
 *
 * Both paths are wrapped in $scope->call(..., WaitReason::input()) so
 * the supervisor sees a typed "input" wait and cancellation propagates.
 *
 * Lifecycle: callers who switch the terminal to raw mode via
 * enableRawMode() must ensure restore() runs on scope teardown. Register
 * the restore via $scope->onDispose(...) at the call site.
 *
 * Non-TTY behaviour: when the source is not a tty (piped input, redirected
 * file, Coroutine\Socket), enableRawMode() throws NonInteractiveTtyException.
 * read() still works on non-tty sources — pipes deliver bytes the same way,
 * and the supervisor wait/cancel semantics are identical.
 *
 * About the fd handling: PHP's int-cast on a stream resource returns the
 * PHP resource ID, NOT the underlying kernel fd. Earlier revisions of this
 * class did the int-cast and passed the wrong number to waitEvent, which
 * the kqueue/epoll reactor rejected with "Bad file descriptor". The fix is
 * to pass the resource itself; OpenSwoole's waitEvent handles stream→fd
 * extraction internally via php_stream_cast(PHP_STREAM_AS_FD).
 */
final class ConsoleInput
{
    public bool $isInteractive {
        get => $this->interactive;
    }

    /** @var resource|null */
    private mixed $stream = null;

    private readonly ?Socket $socket;

    private readonly bool $interactive;

    private bool $rawModeActive = false;

    private ?string $savedSttyState = null;

    /** @param resource|Socket|null $source */
    public function __construct(mixed $source = null)
    {
        $source ??= STDIN;

        if ($source instanceof Socket) {
            $this->socket = $source;
            $this->interactive = false;
            return;
        }

        // waitEvent receives PHP streams directly; non-blocking mode keeps
        // fread from blocking the worker after readiness is signaled.
        $this->stream = $source;
        $this->socket = null;
        $this->interactive = stream_isatty($source);
        @stream_set_blocking($source, false);
    }

    public function read(Suspendable $scope, int $bytes = 1024, ?float $timeout = null): string
    {
        if ($bytes < 1) {
            return '';
        }

        $waitTimeout = $timeout ?? -1.0;

        if ($this->socket !== null) {
            $socket = $this->socket;
            $payload = $scope->call(
                static fn(): bool|string => $socket->recv($bytes, $waitTimeout),
                WaitReason::input(),
            );
            return is_string($payload) ? $payload : '';
        }

        $stream = $this->stream;
        assert($stream !== null, 'ConsoleInput invariant: stream is set when socket is null');

        $ready = $scope->call(
            static fn(): bool|int => System::waitEvent($stream, SWOOLE_EVENT_READ, $waitTimeout),
            WaitReason::input(),
        );

        if ($ready === false) {
            return '';
        }

        $chunk = fread($stream, $bytes);
        return $chunk === false ? '' : $chunk;
    }

    /**
     * Read one line ("\n"-terminated) from the input source.
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
                'Cannot enable raw mode: input source is not a TTY.',
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
