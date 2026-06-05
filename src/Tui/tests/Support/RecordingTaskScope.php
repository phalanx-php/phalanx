<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Support;

use Closure;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;

final class RecordingTaskScope implements TaskScope
{
    /** unsupported: this test double only records task-scope behavior. */
    public RuntimeContext $runtime {
        get => throw new \RuntimeException('Recording task scope does not expose runtime context.');
    }

    /** computed: mirrors the owned cancellation token. */
    public bool $isCancelled {
        get => $this->cancellation->isCancelled;
    }

    private CancellationToken $cancellation;

    private Trace $trace;

    private int $callCount = 0;

    private ?WaitReason $lastWaitReason = null;

    /** @var list<Closure(): void> */
    private array $disposeCallbacks = [];

    public function __construct()
    {
        $this->trace = new Trace();
        $this->cancellation = CancellationToken::create();
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    public function lastWaitReason(): ?WaitReason
    {
        return $this->lastWaitReason;
    }

    public function trace(): Trace
    {
        return $this->trace;
    }

    public function call(Closure $fn, ?WaitReason $waitReason = null): mixed
    {
        $this->callCount++;
        $this->lastWaitReason = $waitReason;

        return $fn();
    }

    public function execute(Scopeable|Executable|Closure $task): mixed
    {
        return $this->executeFresh($task);
    }

    public function executeFresh(Scopeable|Executable|Closure $task): mixed
    {
        if ($task instanceof Closure) {
            return $task();
        }

        if (is_callable($task)) {
            return $task($this);
        }

        throw new \RuntimeException('Recording task scope only executes closures or invokable task objects.');
    }

    public function service(string $type): object
    {
        throw new ServiceNotFoundException($type);
    }

    public function throwIfCancelled(): void
    {
        $this->cancellation->throwIfCancelled();
    }

    public function cancellation(): CancellationToken
    {
        return $this->cancellation;
    }

    public function onDispose(Closure $callback): void
    {
        $this->disposeCallbacks[] = $callback;
    }

    public function dispose(): void
    {
        foreach ($this->disposeCallbacks as $callback) {
            $callback();
        }

        $this->disposeCallbacks = [];
    }
}
