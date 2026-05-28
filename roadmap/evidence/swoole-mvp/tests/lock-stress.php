<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use OpenSwoole\Coroutine as Co;
use OpenSwoole\Runtime;
use Phalanx\Swoole\Mvp\Application;
use Phalanx\Swoole\Mvp\Profile\Composes;
use Phalanx\Swoole\Mvp\Profile\Reads;
use Phalanx\Swoole\Mvp\Profile\Writes;
use Phalanx\Swoole\Mvp\Scope\CompositionScope;
use Phalanx\Swoole\Mvp\Scope\ReadScope;
use Phalanx\Swoole\Mvp\Scope\WriteScope;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

final class Counter
{
    public int $value = 0;

    public int $maxConcurrent = 0;

    public int $currentConcurrent = 0;

    public function enter(): void
    {
        $this->currentConcurrent++;
        if ($this->currentConcurrent > $this->maxConcurrent) {
            $this->maxConcurrent = $this->currentConcurrent;
        }
    }

    public function leave(): void
    {
        $this->currentConcurrent--;
    }
}

final class Increment implements Writes
{
    public function __construct(public int $key) {}

    public static function writes(): array
    {
        return [Counter::class => ['key']];
    }

    public function __invoke(WriteScope $scope): void
    {
        $c = $scope->use(Counter::class);
        $c->enter();
        try {
            $snapshot = $c->value;
            Co::usleep(2_000);
            $c->value = $snapshot + 1;
        } finally {
            $c->leave();
        }
    }
}

final class ReadCounter implements Reads
{
    public static function reads(): array
    {
        return [Counter::class];
    }

    public function __invoke(ReadScope $scope): Counter
    {
        return $scope->use(Counter::class);
    }
}

final class StressBatch implements Composes
{
    /** @param list<object> $tasks */
    public function __construct(private readonly array $tasks) {}

    public function __invoke(CompositionScope $scope): array
    {
        return $scope->parallel($this->tasks);
    }
}

Co::run(static function (): void {
    $app = new Application();
    $app->services()->singleton(Counter::class)
        ->factory(static fn() => new Counter())->capacity(1)->suspending();
    $app->registerTasks(Increment::class, ReadCounter::class, StressBatch::class)->compile()->boot();

    $iterations = 200;
    $tasks = [];
    for ($i = 0; $i < $iterations; $i++) {
        $tasks[] = new Increment(1);
    }

    $t0 = microtime(true);
    $app->dispatcher()->dispatch(new StressBatch($tasks));
    $elapsed = microtime(true) - $t0;

    $counter = $app->dispatcher()->dispatch(new ReadCounter());

    fwrite(STDOUT, sprintf(
        "iterations=%d final=%d max_concurrent_in_critical=%d elapsed=%.3fs\n",
        $iterations,
        $counter->value,
        $counter->maxConcurrent,
        $elapsed,
    ));

    if ($counter->value !== $iterations) {
        fwrite(STDOUT, "FAIL final value != iterations (lost updates from missing serialization)\n");
        exit(1);
    }
    if ($counter->maxConcurrent !== 1) {
        fwrite(STDOUT, sprintf(
            "FAIL max concurrent in critical section was %d (expected 1)\n",
            $counter->maxConcurrent,
        ));
        exit(1);
    }
    fwrite(STDOUT, "PASS  serialization holds under concurrent dispatch\n");
});
