<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use OpenSwoole\Coroutine as Co;
use OpenSwoole\Runtime;
use Phalanx\Swoole\Mvp\Application;
use Phalanx\Swoole\Mvp\Profile\Composes;
use Phalanx\Swoole\Mvp\Profile\Reads;
use Phalanx\Swoole\Mvp\Profile\Writes;
use Phalanx\Swoole\Mvp\Runtime\CapabilityViolation;
use Phalanx\Swoole\Mvp\Runtime\CompileException;
use Phalanx\Swoole\Mvp\Scope\CompositionScope;
use Phalanx\Swoole\Mvp\Scope\ReadScope;
use Phalanx\Swoole\Mvp\Scope\WriteScope;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

final class FakeStore
{
}

final class OtherStore
{
}

final class BadKeyTask implements Writes
{
    public int $id = 1;

    public static function writes(): array
    {
        return [FakeStore::class => ['nonexistentProp']];
    }

    public function __invoke(WriteScope $scope): void
    {
    }
}

final class UndeclaredUseTask implements Reads
{
    public static function reads(): array
    {
        return [FakeStore::class];
    }

    public function __invoke(ReadScope $scope): mixed
    {
        return $scope->use(OtherStore::class);
    }
}

final class WriteCallsRunTask implements Writes
{
    public int $id = 1;

    public static function writes(): array
    {
        return [FakeStore::class => ['id']];
    }

    public function __invoke(WriteScope $scope): mixed
    {
        /** @phpstan-ignore-next-line — WriteScope has no run(); calling it is a static type error */
        return $scope->run(new self());
    }
}

final class TxOnUnsafeTask implements Writes
{
    public int $id = 1;

    public static function writes(): array
    {
        return [OtherStore::class => ['id']];
    }

    public function __invoke(WriteScope $scope): mixed
    {
        return $scope->transaction(static fn(WriteScope $s): mixed => $s->use(OtherStore::class));
    }
}

function expect(string $label, callable $fn, string $expectedClass, string $contains = ''): void
{
    try {
        $fn();
        fwrite(STDOUT, "FAIL  {$label}: expected {$expectedClass}, no exception\n");
        return;
    } catch (\Throwable $e) {
        if (! $e instanceof $expectedClass) {
            fwrite(STDOUT, sprintf(
                "FAIL  %s: expected %s, got %s (%s)\n",
                $label,
                $expectedClass,
                $e::class,
                $e->getMessage(),
            ));
            return;
        }
        if ($contains !== '' && ! str_contains($e->getMessage(), $contains)) {
            fwrite(STDOUT, sprintf(
                "FAIL  %s: expected message to contain %s, got %s\n",
                $label,
                var_export($contains, true),
                $e->getMessage(),
            ));
            return;
        }
        fwrite(STDOUT, "PASS  {$label}: {$e->getMessage()}\n");
    }
}

Co::run(static function (): void {
    expect(
        'compile rejects unknown property in writes()',
        static function (): void {
            $app = new Application();
            $app->services()->singleton(FakeStore::class)
                ->factory(static fn() => new FakeStore())->capacity(1)->suspending();
            $app->registerTasks(BadKeyTask::class)->compile();
        },
        CompileException::class,
        'nonexistentProp',
    );

    expect(
        'compile rejects writes() with unregistered resource',
        static function (): void {
            $app = new Application();
            $app->registerTasks(BadKeyTask::class)->compile();
        },
        CompileException::class,
        'unregistered resource',
    );

    expect(
        'runtime use() rejects undeclared resource (Reads profile)',
        static function (): void {
            $app = new Application();
            $app->services()->singleton(FakeStore::class)
                ->factory(static fn() => new FakeStore())->capacity(1)->suspending();
            $app->services()->singleton(OtherStore::class)
                ->factory(static fn() => new OtherStore())->capacity(1)->suspending();
            $app->registerTasks(UndeclaredUseTask::class)->compile()->boot();
            $app->dispatcher()->dispatch(new UndeclaredUseTask());
        },
        CapabilityViolation::class,
        'did not declare reads',
    );

    expect(
        'WriteScope lacks composition verbs at runtime (capability violation)',
        static function (): void {
            $app = new Application();
            $app->services()->singleton(FakeStore::class)
                ->factory(static fn() => new FakeStore())->capacity(1)->suspending();
            $app->registerTasks(WriteCallsRunTask::class)->compile()->boot();
            $app->dispatcher()->dispatch(new WriteCallsRunTask());
        },
        CapabilityViolation::class,
        'run() not available in writes scope',
    );

    expect(
        'transaction() rejects non-transactionSafe resource',
        static function (): void {
            $app = new Application();
            $app->services()->singleton(OtherStore::class)
                ->factory(static fn() => new OtherStore())->capacity(1)->suspending();
            $app->registerTasks(TxOnUnsafeTask::class)->compile()->boot();
            $app->dispatcher()->dispatch(new TxOnUnsafeTask());
        },
        CapabilityViolation::class,
        'transactionSafe',
    );

    expect(
        'compile rejects task implementing two profile interfaces',
        static function (): void {
            $cls = new class implements Reads, Writes {
                public int $id = 1;
                public static function reads(): array { return []; }
                public static function writes(): array { return []; }
                public function __invoke(): void {}
            };
            $app = new Application();
            $app->registerTasks($cls::class)->compile();
        },
        CompileException::class,
        'exactly one profile',
    );
});
