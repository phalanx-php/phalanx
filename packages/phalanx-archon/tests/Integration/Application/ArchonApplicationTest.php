<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Application;

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Application\ConsoleConfig;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalPolicy;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalState;
use Phalanx\Archon\Runtime\Identity\ArchonAnnotationSid;
use Phalanx\Archon\Runtime\Identity\ArchonResourceSid;
use Phalanx\Archon\Command\Opt;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Testing\Assert as PhalanxAssert;
use Phalanx\Tests\Support\CoroutineTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ArchonApplicationTest extends CoroutineTestCase
{
    #[Test]
    public function facadeBuildsDispatchableCommandFirstApplication(): void
    {
        $app = Archon::starting()
            ->commands(CommandGroup::of([
                'probe' => ArchonApplicationProbeCommand::class,
            ]))
            ->build();

        $this->runInCoroutine(static function () use ($app): void {
            self::assertSame(0, $app->dispatch(['probe']));
        });

        self::assertSame('probe', ArchonApplicationProbeCommand::$commandName);
        self::assertNotSame('', ArchonApplicationProbeCommand::$commandResourceId);
        self::assertSame('task:probe', ArchonApplicationProbeCommand::$taskResult);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Closed, $resource->state);
        self::assertSame(0, $app->host()->runtime()->memory->resources->liveCount(AegisResourceSid::Scope));
        PhalanxAssert::assertNoLiveTasks($app->host()->supervisor());
        $app->shutdown();
    }

    #[Test]
    public function runUsesContextArgvWithoutLeakingScriptNameIntoDispatch(): void
    {
        $app = Archon::starting([
            'argv' => ['bin/phalanx', 'probe'],
        ])
            ->commands(CommandGroup::of([
                'probe' => ArchonApplicationProbeCommand::class,
            ]))
            ->build();

        $this->runInCoroutine(static function () use ($app): void {
            self::assertSame(0, $app->run());
        });

        self::assertSame('probe', ArchonApplicationProbeCommand::$commandName);
    }

    #[Test]
    public function runEntersTheAegisCoroutineRuntime(): void
    {
        $app = Archon::starting([
            'argv' => ['bin/phalanx', 'delay'],
        ])
            ->commands(CommandGroup::of([
                'delay' => CoroutineRuntimeProbeCommand::class,
            ]))
            ->build();

        self::assertSame(0, $app->run());
        self::assertTrue(CoroutineRuntimeProbeCommand::$ran);
    }

    #[Test]
    public function oneOffCommandFacadeReceivesCommandScopeAndParsedInput(): void
    {
        $received = null;
        $config = new CommandConfig(
            arguments: [Arg::required('image')],
            options: [Opt::flag('detach', 'd')],
        );

        $builder = Archon::command(
            'deploy',
            static function (CommandScope $scope) use (&$received): string {
                $received = [
                    $scope->commandName,
                    $scope->args->get('image'),
                    $scope->options->flag('detach'),
                    $scope->execute(Task::named(
                        'archon.inline.probe',
                        static fn(ExecutionScope $taskScope): string => 'task:' . $taskScope->attribute('command'),
                    )),
                ];

                return 'ok';
            },
            $config,
        );
        $app = $builder->build();

        $this->runInCoroutine(static function () use ($app): void {
            self::assertSame(0, $app->dispatch(['deploy', 'nginx', '--detach']));
        });

        self::assertSame(['deploy', 'nginx', true, 'task:deploy'], $received);
        self::assertSame(0, $app->host()->runtime()->memory->resources->liveCount(ArchonResourceSid::Command));
        self::assertSame(0, $app->host()->runtime()->memory->resources->liveCount(AegisResourceSid::Scope));
        PhalanxAssert::assertNoLiveTasks($app->host()->supervisor());
        $app->shutdown();
    }

    #[Test]
    public function throwingOneOffCommandReturnsNonZeroAndDisposesScope(): void
    {
        $disposed = false;
        $stream = StreamOutputHelper::open();
        $app = Archon::command(
            'fail',
            static function (CommandScope $scope) use (&$disposed): int {
                $scope->onDispose(static function () use (&$disposed): void {
                    $disposed = true;
                });

                throw new \RuntimeException('expected failure');
            },
        )
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $stream): void {
            $code = $app->dispatch(['fail']);

            self::assertSame(1, $code);
            self::assertStringContainsString('Error: expected failure', StreamOutputHelper::contents($stream));
        });

        self::assertTrue($disposed);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Failed, $resource->state);
        PhalanxAssert::assertNoLiveTasks($app->host()->supervisor());
        $app->shutdown();
    }

    #[Test]
    public function unknownCommandReturnsNonZeroWithAvailableCommands(): void
    {
        $stream = StreamOutputHelper::open();
        $app = Archon::command('known', static fn(CommandScope $scope): int => 0)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $stream): void {
            $code = $app->dispatch(['missing']);

            self::assertSame(1, $code);
            self::assertStringContainsString('Unknown command: missing', StreamOutputHelper::contents($stream));
            self::assertStringContainsString('known', StreamOutputHelper::contents($stream));
        });

        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Failed, $resource->state);
        self::assertSame('unknown_command', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ArchonAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function helpForMissingCommandReturnsNonZero(): void
    {
        $stream = StreamOutputHelper::open();
        $app = Archon::command('known', static fn(CommandScope $scope): int => 0)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $stream): void {
            $code = $app->dispatch(['help', 'missing']);

            self::assertSame(1, $code);
            self::assertStringContainsString('Unknown command: missing', StreamOutputHelper::contents($stream));
        });

        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Failed, $resource->state);
        self::assertSame('unknown_command', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ArchonAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function longUnknownCommandCannotOverflowManagedResourceAnnotations(): void
    {
        $stream = StreamOutputHelper::open();
        $missing = str_repeat('x', 400);
        $app = Archon::command('known', static fn(CommandScope $scope): int => 0)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $missing): void {
            self::assertSame(1, $app->dispatch([$missing]));
        });

        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Failed, $resource->state);
        self::assertSame('unknown_command', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ArchonAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function cancelledCommandAbortsManagedCommandResource(): void
    {
        $stream = StreamOutputHelper::open();
        $app = Archon::command(
            'cancel',
            static function (CommandScope $scope): int {
                throw new Cancelled('signal:int');
            },
        )
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $stream): void {
            self::assertSame(130, $app->dispatch(['cancel']));
            self::assertStringContainsString('Cancelled: signal:int', StreamOutputHelper::contents($stream));
        });

        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Aborted, $resource->state);
        self::assertSame('cancelled', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ArchonAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function scopedDispatchUsesBorrowedCancellationToken(): void
    {
        $stream = StreamOutputHelper::open();
        $token = null;
        $app = Archon::command(
            'cancel',
            static function (CommandScope $scope) use (&$token): int {
                self::assertSame($token, $scope->cancellation());
                $scope->cancellation()->cancel();
                $scope->throwIfCancelled();

                return 0;
            },
        )
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $stream, &$token): void {
            $token = CancellationToken::create();
            $scope = $app->host()->createScope($token);

            try {
                $code = $app->dispatchScoped(['cancel'], $scope);

                self::assertSame(130, $code);
                self::assertStringContainsString('Cancelled: scope cancelled', StreamOutputHelper::contents($stream));
            } finally {
                $scope->dispose();
            }
        });

        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Aborted, $resource->state);
        self::assertSame('130', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ArchonAnnotationSid::ExitCode->value()]);
        self::assertSame(0, $app->host()->runtime()->memory->resources->liveCount(AegisResourceSid::Scope));
        PhalanxAssert::assertNoLiveTasks($app->host()->supervisor());
        $app->shutdown();
    }

    #[Test]
    public function signalCancellationUsesSignalExitCodeAndReason(): void
    {
        $stream = StreamOutputHelper::open();
        $signals = new ConsoleSignalState();
        $signal = ConsoleSignalPolicy::forSignals([15 => 143])->signal(15);
        self::assertNotNull($signal);
        $signals->record($signal);

        $app = Archon::command(
            'cancel',
            static function (CommandScope $scope): int {
                $scope->cancellation()->cancel();
                $scope->throwIfCancelled();

                return 0;
            },
        )
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $signals, $stream): void {
            $token = CancellationToken::create();
            $scope = $app->host()->createScope($token);

            try {
                $code = $app->dispatchScoped(['cancel'], $scope, $signals);

                self::assertSame(143, $code);
                self::assertStringContainsString('Cancelled: signal:term', StreamOutputHelper::contents($stream));
            } finally {
                $scope->dispose();
            }
        });

        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Aborted, $resource->state);
        self::assertSame('143', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ArchonAnnotationSid::ExitCode->value()]);
        $app->shutdown();
    }

    #[Test]
    public function runCancelsThroughConfiguredSignalTrap(): void
    {
        if (!defined('SIGUSR1') || !extension_loaded('openswoole')) {
            self::markTestSkipped('Signal integration requires SIGUSR1 and OpenSwoole.');
        }

        $stream = StreamOutputHelper::open();
        $app = Archon::command(
            'signal',
            static function (CommandScope $scope): int {
                $pid = getmypid();
                self::assertIsInt($pid);

                \OpenSwoole\Process::kill($pid, SIGUSR1);
                \OpenSwoole\Coroutine::usleep(50_000);
                $scope->throwIfCancelled();

                return 0;
            },
        )
            ->withConsoleConfig(new ConsoleConfig(
                argv: ['signal'],
                errorOutput: StreamOutputHelper::output($stream),
                signalPolicy: ConsoleSignalPolicy::forSignals([SIGUSR1 => 138]),
            ))
            ->build();

        self::assertSame(138, $app->run());
        self::assertStringContainsString('Cancelled: signal:' . SIGUSR1, StreamOutputHelper::contents($stream));
    }

    #[Test]
    public function oneOffCommandFacadeRejectsNonStaticClosures(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('inline commands require static closures');

        Archon::command('leaky', fn(CommandScope $scope): int => 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        ArchonApplicationProbeCommand::$commandName = null;
        ArchonApplicationProbeCommand::$commandResourceId = null;
        ArchonApplicationProbeCommand::$taskResult = null;
        CoroutineRuntimeProbeCommand::$ran = false;
    }
}

final class ArchonApplicationProbeCommand implements Scopeable
{
    public static ?string $commandName = null;

    public static ?string $commandResourceId = null;

    public static ?string $taskResult = null;

    public function __invoke(CommandScope $scope): int
    {
        self::$commandName = $scope->commandName;
        self::$commandResourceId = $scope->commandResourceId;
        self::$taskResult = $scope->execute(Task::named(
            'archon.application.proof',
            static fn(ExecutionScope $taskScope): string => 'task:' . $taskScope->attribute('command'),
        ));

        return 0;
    }
}

final class CoroutineRuntimeProbeCommand implements Scopeable
{
    public static bool $ran = false;

    public function __invoke(CommandScope $scope): int
    {
        $scope->delay(0.001);
        self::$ran = true;

        return 0;
    }
}
