<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Runtime;

use LogicException;
use OpenSwoole\Coroutine;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;
use PHPUnit\Framework\TestCase;

final class ManagedApplicationRunnerTest extends TestCase
{
    public function testBuilderRunExecutesTaskAndReturnsResult(): void
    {
        $ledger = new InProcessLedger();

        $result = Application::starting([])
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
        $app = Application::starting([])
            ->providers(new ManagedRunnerBundle($events))
            ->compile();

        $result = $app->run(Task::named(
            'runner.lifecycle',
            static fn(): string => 'done',
        ));

        self::assertSame('done', $result);
        self::assertSame(['startup', 'shutdown'], $events->entries);
    }

    public function testScopedStartsButDoesNotShutDownTheApplication(): void
    {
        $events = new ManagedRunnerEvents();
        $app = Application::starting([])
            ->providers(new ManagedRunnerBundle($events))
            ->compile();

        $result = $app->scoped(Task::named(
            'runner.scoped',
            static fn(): string => 'scoped',
        ));

        self::assertSame('scoped', $result);
        self::assertSame(['startup'], $events->entries);

        $app->shutdown();

        self::assertSame(['startup', 'shutdown'], $events->entries);
    }

    public function testRunRethrowsTheOriginalException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('boom');

        Application::starting([])->run(Task::named(
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
            Application::starting([])
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

        Coroutine::run(static function () use (&$caught, &$result): void {
            try {
                $result = Application::starting([])->run(Task::named(
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

final class ManagedRunnerBundle implements ServiceBundle
{
    public function __construct(
        private readonly ManagedRunnerEvents $events,
    ) {
    }

    public function services(Services $services, array $context): void
    {
        $events = $this->events;

        $services->eager(ManagedRunnerProbe::class)
            ->factory(static fn(): ManagedRunnerProbe => new ManagedRunnerProbe())
            ->onStartup(static function () use ($events): void {
                $events->record('startup');
            })
            ->onShutdown(static function () use ($events): void {
                $events->record('shutdown');
            });
    }
}
