<?php

declare(strict_types=1);

namespace Phalanx\Archon\Input;

use Closure;
use Phalanx\Scope\ExecutionScope;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use WeakReference;

/**
 * Non-blocking stdin reader with raw-mode lifecycle management.
 *
 * Call order per input session:
 *   enable() → attach() → [nextKey() calls] → detach() → disable()
 *
 * stty is invoked exactly twice: once to save state (enable) and once to restore
 * (disable). ~10ms per call — never call inside a render loop.
 *
 * disable() MUST be called in a finally block. Raw mode persists across process
 * exit and leaves the parent shell unusable until the user types `reset`.
 *
 * The nextKey() queue is FIFO. Sequential form flow naturally has exactly one
 * pending deferred at a time — if two callers race, each receives keys in order.
 *
 * Non-TTY: all lifecycle methods are no-ops. nextKey() returns a promise that
 * never resolves. Callers must check isTty() and return defaults immediately.
 */
final class RawInput
{
    private string $savedStty = '';
    private bool $active      = false;
    private bool $attached    = false;
    private ?bool $previousBlocking = null;

    /** @var list<Deferred<string>> */
    private array $pending = [];

    private readonly KeyParser $parser;

    /** @var Closure(string): string */
    private Closure $stty;

    /** @param resource|null $stream */
    public function __construct(
        private readonly bool $isTty = true,
        private mixed $stream = null,
        ?Closure $stty = null,
    ) {
        $this->stream = $stream ?? STDIN;
        $this->stty = $stty ?? static fn(string $command): string => trim((string) shell_exec($command));
        $this->parser = new KeyParser();
    }

    public static function fromStdin(): self
    {
        return new self(stream_isatty(STDIN));
    }

    /** @param resource $stream */
    private static function isBlocking(mixed $stream): bool
    {
        /** @var array<string, mixed> $metadata */
        $metadata = stream_get_meta_data($stream);

        return ($metadata['blocked'] ?? true) === true;
    }

    public function enable(): void
    {
        if (!$this->isTty || $this->active) {
            return;
        }

        /**
         * Blocking shell_exec is acceptable here because raw mode is entered once
         * before the input loop starts, not inside a timer or stream callback.
         */
        $this->savedStty = ($this->stty)('stty -g 2>/dev/null');
        if ($this->savedStty === '') {
            return;
        }

        ($this->stty)('stty -icanon -isig -echo -ixon min 1 time 0 2>/dev/null');
        $this->active = true;
    }

    public function attach(): void
    {
        if (!$this->isTty || $this->attached) {
            return;
        }

        $stream = $this->stream;
        assert(is_resource($stream));

        $this->previousBlocking = self::isBlocking($stream);
        stream_set_blocking($stream, false);

        $ref = WeakReference::create($this);

        /**
         * Bind a static closure to RawInput scope so it can call dispatch()
         * without capturing this object through the loop listener registry.
         */
        $handler = Closure::bind(
            static function ($stream) use ($ref): void {
                $self = $ref->get();
                if ($self === null) {
                    return;
                }
                $data = fread($stream, 1024);
                if ($data !== false && $data !== '') {
                    $self->dispatch($data);
                }
            },
            null,
            self::class,
        );

        Loop::addReadStream($stream, $handler);
        $this->attached = true;
    }

    /** @return PromiseInterface<mixed> */
    public function nextKey(): PromiseInterface
    {
        $deferred        = new Deferred();
        $this->pending[] = $deferred;
        return $deferred->promise();
    }

    public function detach(): void
    {
        if (!$this->attached) {
            return;
        }

        $stream = $this->stream;
        assert(is_resource($stream));

        Loop::removeReadStream($stream);
        stream_set_blocking($stream, $this->previousBlocking ?? true);
        $this->attached = false;
        $this->previousBlocking = null;
    }

    public function disable(): void
    {
        if (!$this->active) {
            return;
        }

        if ($this->savedStty !== '') {
            ($this->stty)("stty {$this->savedStty} 2>/dev/null");
        }

        $this->active    = false;
        $this->savedStty = '';
    }

    public function restore(): void
    {
        $this->detach();
        $this->disable();
    }

    public function restoreOnDispose(ExecutionScope $scope): self
    {
        $input = $this;
        $scope->onDispose(static function () use ($input): void {
            $input->restore();
        });

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isAttached(): bool
    {
        return $this->attached;
    }

    public function isTty(): bool
    {
        return $this->isTty;
    }

    private function dispatch(string $data): void
    {
        foreach ($this->parser->parse($data) as $key) {
            if ($this->pending === []) {
                break;
            }
            array_shift($this->pending)->resolve($key);
        }
    }
}
