<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Closure;
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

    /** @var list<Deferred<string>> */
    private array $pending = [];

    private readonly KeyParser $parser;

    public function __construct(private readonly bool $isTty = true)
    {
        $this->parser = new KeyParser();
    }

    public static function fromStdin(): self
    {
        return new self(stream_isatty(STDIN));
    }

    public function enable(): void
    {
        if (!$this->isTty || $this->active) {
            return;
        }

        // Blocking shell_exec is acceptable here — called once before the event loop
        // begins processing input, not inside a timer or stream callback.
        $this->savedStty = trim((string) shell_exec('stty -g 2>/dev/null'));
        shell_exec('stty -icanon -isig -echo -ixon min 1 time 0 2>/dev/null');
        $this->active = true;
    }

    public function attach(): void
    {
        if (!$this->isTty || $this->attached) {
            return;
        }

        stream_set_blocking(STDIN, false);

        $ref = WeakReference::create($this);

        // Static closure bound to RawInput class scope via Closure::bind so it can
        // access private dispatch() without capturing $this — breaks the reference
        // cycle between the loop's stream listener registry and this object.
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

        Loop::addReadStream(STDIN, $handler);
        $this->attached = true;
    }

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

        Loop::removeReadStream(STDIN);
        stream_set_blocking(STDIN, true);
        $this->attached = false;
    }

    public function disable(): void
    {
        if (!$this->active) {
            return;
        }

        if ($this->savedStty !== '') {
            shell_exec("stty {$this->savedStty} 2>/dev/null");
        }

        $this->active    = false;
        $this->savedStty = '';
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
