<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Runtime;

use LogicException;
use RuntimeException;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Runtime\Tests\Support\Fixtures\DependentLifecycleBundle;
use Phalanx\Runtime\Tests\Support\Fixtures\FailingStartupLifecycleBundle;
use Phalanx\Runtime\Tests\Support\Fixtures\InvalidReadyLifecycleBundle;
use Phalanx\Runtime\Tests\Support\Fixtures\InvalidStartupLifecycleBundle;
use Phalanx\Runtime\Tests\Support\Fixtures\ManagedRunnerBundle;
use Phalanx\Runtime\Tests\Support\Fixtures\ManagedRunnerEvents;
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

    public function testEagerStartupAndReadyHooksFollowDependencyCreationOrder(): void
    {
        $events = new ManagedRunnerEvents();
        $app = Application::starting()
            ->providers(new DependentLifecycleBundle($events))
            ->compile();

        $app->startup();

        self::assertSame([
            'dependency.init',
            'dependent.init',
            'dependency.startup',
            'dependent.startup',
            'dependency.ready',
            'dependent.ready',
        ], $events->entries);
    }

    public function testStartupFailureCleansPartialSingletonsAndCanRetry(): void
    {
        $events = new ManagedRunnerEvents();
        $app = Application::starting()
            ->providers(new FailingStartupLifecycleBundle($events))
            ->compile();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $app->startup();
                self::fail('Expected startup failure.');
            } catch (LogicException $e) {
                self::assertSame('startup failed', $e->getMessage());
            }
        }

        self::assertSame([
            'init',
            'startup',
            'shutdown',
            'init',
            'startup',
            'shutdown',
        ], $events->entries);
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
