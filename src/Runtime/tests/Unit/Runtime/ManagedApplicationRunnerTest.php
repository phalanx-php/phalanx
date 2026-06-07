<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Runtime;

use LogicException;
use RuntimeException;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

use function Swoole\Coroutine\run as swoole_coroutine_run;

final class ManagedApplicationRunnerTest extends TestCase
{
    public function testBuilderRunExecutesTaskAndReturnsResult(): void
    {
        $ledger = new InProcessLedger();

        $result = Application::starting()
            ->withLedger($ledger)
            ->run(Task::named(
                'runner.result',
                static fn(): string => 'ok',
            ));

        self::assertSame('ok', $result);
        self::assertSame(0, $ledger->liveCount());
    }

    public function testApplicationRunStartsAndShutsDownAroundTask(): void
    {
        $events = new ManagedRunnerEvents();
        $app = Application::starting()
            ->providers(new ManagedRunnerBundle($events))
            ->compile();

        $result = $app->run(Task::named(
            'runner.lifecycle',
            static fn(): string => 'done',
        ));

        self::assertSame('done', $result);
        self::assertSame(['init', 'startup', 'ready', 'shutdown'], $events->entries);
    }

    public function testScopedStartsButDoesNotShutDownTheApplication(): void
    {
        $events = new ManagedRunnerEvents();
        $app = Application::starting()
            ->providers(new ManagedRunnerBundle($events))
            ->compile();

        $result = $app->scoped(Task::named(
            'runner.scoped',
            static fn(): string => 'scoped',
        ));

        self::assertSame('scoped', $result);
        self::assertSame(['init', 'startup', 'ready'], $events->entries);

        $app->shutdown();

        self::assertSame(['init', 'startup', 'ready', 'shutdown'], $events->entries);
    }

    public function testStartupHooksRequireEagerSingletonServices(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('onStartup');

        Application::starting()
            ->providers(new InvalidStartupLifecycleBundle())
            ->compile();
    }

    public function testReadyHooksRequireEagerSingletonServices(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('onReady');

        Application::starting()
            ->providers(new InvalidReadyLifecycleBundle())
            ->compile();
    }

    public function testRunRethrowsTheOriginalException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('boom');

        Application::starting()->run(Task::named(
            'runner.exception',
            static function (): never {
                throw new LogicException('boom');
            },
        ));
    }

    public function testCancelledTokenIsHonoredAndLedgerIsCleaned(): void
    {
        $ledger = new InProcessLedger();
        $token = CancellationToken::create();
        $token->cancel();

        try {
            Application::starting()
                ->withLedger($ledger)
                ->run(Task::named(
                    'runner.cancelled',
                    static fn(): string => 'never',
                ), $token);

            self::fail('Expected managed runner to throw cancellation.');
        } catch (Cancelled) {
            self::assertSame(0, $ledger->liveCount());
        }
    }

    public function testRunInsideExistingCoroutineDoesNotNestCoroutineRun(): void
    {
        $caught = null;
        $result = null;

        swoole_coroutine_run(static function () use (&$caught, &$result): void {
            try {
                $result = Application::starting()->run(Task::named(
                    'runner.existing-coroutine',
                    static fn(): string => Coroutine::getCid() >= 0 ? 'inside' : 'outside',
                ));
            } catch (\Throwable $e) {
                $caught = $e;
            }
        });

        if ($caught !== null) {
            throw $caught;
        }

        self::assertSame('inside', $result);
    }
}

final class ManagedRunnerEvents
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $event): void
    {
        $this->entries[] = $event;
    }
}

final class ManagedRunnerProbe
{
}

final class ManagedRunnerBundle extends ServiceBundle
{
    public function __construct(
        private readonly ManagedRunnerEvents $events,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $events = $this->events;

        $services->eager(ManagedRunnerProbe::class)
            ->factory(static fn(): ManagedRunnerProbe => new ManagedRunnerProbe())
            ->onInit(static function () use ($events): void {
                $events->record('init');
            })
            ->onStartup(static function () use ($events): void {
                $events->record('startup');
            })
            ->onReady(static function () use ($events): void {
                $events->record('ready');
            })
            ->onShutdown(static function () use ($events): void {
                $events->record('shutdown');
            });
    }
}

final class InvalidStartupLifecycleBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(ManagedRunnerProbe::class)
            ->factory(static fn(): ManagedRunnerProbe => new ManagedRunnerProbe())
            ->onStartup(static function (): void {
            });
    }
}

final class InvalidReadyLifecycleBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->scoped(ManagedRunnerProbe::class)
            ->factory(static fn(): ManagedRunnerProbe => new ManagedRunnerProbe())
            ->onReady(static function (): void {
            });
    }
}
