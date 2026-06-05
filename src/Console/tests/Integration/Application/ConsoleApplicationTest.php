<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration\Application;

use Phalanx\Console\Tests\Support\ConsoleTestCase;
use Phalanx\Console\Application\ConsoleConfig;
use Phalanx\Console\Command\Arg;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Command\Opt;
use Phalanx\Console\Runtime\Identity\ConsoleAnnotationSid;
use Phalanx\Console\Runtime\Identity\ConsoleResourceSid;
use Phalanx\Console\Runtime\Identity\ConsoleSignalPolicy;
use Phalanx\Console\Runtime\Identity\ConsoleSignalState;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Mark\Mark;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Testing\Assert as PhalanxAssert;
use PHPUnit\Framework\Attributes\Test;

final class ConsoleApplicationTest extends ConsoleTestCase
{
    #[Test]
    public function facadeBuildsDispatchableCommandFirstApplication(): void
    {
        $app = self::console()
            ->commands(CommandGroup::of([
                'probe' => ConsoleApplicationProbeCommand::class,
            ]))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            self::assertSame(0, $app->dispatch(['probe']));
        });

        self::assertSame('probe', ConsoleApplicationProbeCommand::$commandName);
        self::assertNotSame('', ConsoleApplicationProbeCommand::$commandResourceId);
        self::assertSame('task:probe', ConsoleApplicationProbeCommand::$taskResult);
        $resource = $app->host()->runtime()->memory->resources->all(ConsoleResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Closed, $resource->state);
        self::assertSame(0, $app->host()->runtime()->memory->resources->liveCount(RuntimeResourceSid::Scope));
        PhalanxAssert::assertNoLiveTasks($app->host()->supervisor());
        $app->shutdown();
    }

    #[Test]
    public function runUsesContextArgvWithoutLeakingScriptNameIntoDispatch(): void
    {
        $app = self::console([
            'argv' => ['bin/phalanx', 'probe'],
        ])
            ->commands(CommandGroup::of([
                'probe' => ConsoleApplicationProbeCommand::class,
            ]))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            self::assertSame(0, $app->run());
        });

        self::assertSame('probe', ConsoleApplicationProbeCommand::$commandName);
    }

    #[Test]
    public function runEntersTheRuntimeCoroutineRuntime(): void
    {
        $app = self::console([
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
    public function oneOffCommandFacadeReceivesCommandContextAndParsedInput(): void
    {
        $received = null;
        $config = new CommandConfig(
            arguments: [Arg::required('image')],
            options: [Opt::flag('detach', 'd')],
        );

        $builder = self::consoleCommand(
            'deploy',
            static function (CommandContext $ctx) use (&$received): string {
                $commandName = $ctx->commandName;
                $received = [
                    $ctx->commandName,
                    $ctx->args->get('image'),
                    $ctx->options->flag('detach'),
                    $ctx->execute(Task::named(
                        'console.inline.probe',
                        static fn(): string => "task:$commandName",
                    )),
                ];

                return 'ok';
            },
            $config,
        );
        $app = $builder->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            self::assertSame(0, $app->dispatch(['deploy', 'nginx', '--detach']));
        });

        self::assertSame(['deploy', 'nginx', true, 'task:deploy'], $received);
        self::assertSame(0, $app->host()->runtime()->memory->resources->liveCount(ConsoleResourceSid::Command));
        self::assertSame(0, $app->host()->runtime()->memory->resources->liveCount(RuntimeResourceSid::Scope));
        PhalanxAssert::assertNoLiveTasks($app->host()->supervisor());
        $app->shutdown();
    }

    #[Test]
    public function throwingOneOffCommandReturnsNonZeroAndDisposesScope(): void
    {
        $disposed = false;
        $stream = StreamOutputHelper::open();
        $app = self::consoleCommand(
            'fail',
            static function (CommandContext $ctx) use (&$disposed): int {
                $ctx->onDispose(static function () use (&$disposed): void {
                    $disposed = true;
                });

                throw new \RuntimeException('expected failure');
            },
        )
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app, $stream): void {
            $code = $app->dispatch(['fail']);

            self::assertSame(1, $code);
            self::assertStringContainsString('ERROR', StreamOutputHelper::contents($stream));
            self::assertStringContainsString('expected failure', StreamOutputHelper::contents($stream));
        });

        self::assertTrue($disposed);
        $resource = $app->host()->runtime()->memory->resources->all(ConsoleResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Failed, $resource->state);
        PhalanxAssert::assertNoLiveTasks($app->host()->supervisor());
        $app->shutdown();
    }

    #[Test]
    public function unknownCommandReturnsNonZeroWithAvailableCommands(): void
    {
        $stream = StreamOutputHelper::open();
        $app = self::consoleCommand('known', static fn(CommandContext $_ctx): int => 0)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app, $stream): void {
            $code = $app->dispatch(['missing']);

            self::assertSame(1, $code);
            self::assertStringContainsString('Unknown command: missing', StreamOutputHelper::contents($stream));
            self::assertStringContainsString('known', StreamOutputHelper::contents($stream));
        });

        $resource = $app->host()->runtime()->memory->resources->all(ConsoleResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Failed, $resource->state);
        self::assertSame('unknown_command', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ConsoleAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function helpForMissingCommandReturnsNonZero(): void
    {
        $stream = StreamOutputHelper::open();
        $app = self::consoleCommand('known', static fn(CommandContext $_ctx): int => 0)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app, $stream): void {
            $code = $app->dispatch(['help', 'missing']);

            self::assertSame(1, $code);
            self::assertStringContainsString('Unknown command: missing', StreamOutputHelper::contents($stream));
        });

        $resource = $app->host()->runtime()->memory->resources->all(ConsoleResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Failed, $resource->state);
        self::assertSame('unknown_command', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ConsoleAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function longUnknownCommandCannotOverflowManagedResourceAnnotations(): void
    {
        $stream = StreamOutputHelper::open();
        $missing = str_repeat('x', 400);
        $app = self::consoleCommand('known', static fn(CommandContext $_ctx): int => 0)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app, $missing): void {
            self::assertSame(1, $app->dispatch([$missing]));
        });

        $resource = $app->host()->runtime()->memory->resources->all(ConsoleResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Failed, $resource->state);
        self::assertSame('unknown_command', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ConsoleAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function cancelledCommandAbortsManagedCommandResource(): void
    {
        $stream = StreamOutputHelper::open();
        $app = self::consoleCommand(
            'cancel',
            static function (CommandContext $_ctx): int {
                throw new Cancelled('signal:int');
            },
        )
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app, $stream): void {
            self::assertSame(130, $app->dispatch(['cancel']));
            self::assertStringContainsString('Cancelled: signal:int', StreamOutputHelper::contents($stream));
        });

        $resource = $app->host()->runtime()->memory->resources->all(ConsoleResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Aborted, $resource->state);
        self::assertSame('cancelled', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ConsoleAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function scopedDispatchUsesBorrowedCancellationToken(): void
    {
        $stream = StreamOutputHelper::open();
        $token = null;
        $app = self::consoleCommand(
            'cancel',
            static function (CommandContext $ctx) use (&$token): int {
                self::assertSame($token, $ctx->cancellation());
                $ctx->cancellation()->cancel();
                $ctx->throwIfCancelled();

                return 0;
            },
        )
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app, $stream, &$token): void {
            $token = CancellationToken::create();
            $outerScope = $app->host()->createScope($token);

            try {
                $code = $app->dispatchScoped(['cancel'], $outerScope);

                self::assertSame(130, $code);
                self::assertStringContainsString('Cancelled: scope cancelled', StreamOutputHelper::contents($stream));
            } finally {
                $outerScope->dispose();
            }
        });

        $resource = $app->host()->runtime()->memory->resources->all(ConsoleResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Aborted, $resource->state);
        self::assertSame('130', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ConsoleAnnotationSid::ExitCode->value()]);
        self::assertSame(0, $app->host()->runtime()->memory->resources->liveCount(RuntimeResourceSid::Scope));
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

        $app = self::consoleCommand(
            'cancel',
            static function (CommandContext $ctx): int {
                $ctx->cancellation()->cancel();
                $ctx->throwIfCancelled();

                return 0;
            },
        )
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app, $signals, $stream): void {
            $token = CancellationToken::create();
            $outerScope = $app->host()->createScope($token);

            try {
                $code = $app->dispatchScoped(['cancel'], $outerScope, $signals);

                self::assertSame(143, $code);
                self::assertStringContainsString('Cancelled: signal:term', StreamOutputHelper::contents($stream));
            } finally {
                $outerScope->dispose();
            }
        });

        $resource = $app->host()->runtime()->memory->resources->all(ConsoleResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Aborted, $resource->state);
        self::assertSame('143', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ConsoleAnnotationSid::ExitCode->value()]);
        $app->shutdown();
    }

    #[Test]
    public function runCancelsThroughConfiguredSignalTrap(): void
    {
        if (!defined('SIGUSR1') || !extension_loaded('swoole')) {
            self::markTestSkipped('Signal integration requires SIGUSR1 and Swoole.');
        }

        $stream = StreamOutputHelper::open();
        $app = self::consoleCommand(
            'signal',
            static function (CommandContext $ctx): int {
                $pid = getmypid();
                self::assertIsInt($pid);

                \Swoole\Process::kill($pid, SIGUSR1);
                \Swoole\Coroutine::sleep(0.05);
                $ctx->throwIfCancelled();

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

        self::consoleCommand('leaky', fn(CommandContext $_ctx): int => 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        ConsoleApplicationProbeCommand::$commandName = null;
        ConsoleApplicationProbeCommand::$commandResourceId = null;
        ConsoleApplicationProbeCommand::$taskResult = null;
        CoroutineRuntimeProbeCommand::$ran = false;
    }
}

final class ConsoleApplicationProbeCommand implements Scopeable
{
    public static ?string $commandName = null;

    public static ?string $commandResourceId = null;

    public static ?string $taskResult = null;

    public function __invoke(CommandContext $ctx): int
    {
        self::$commandName = $ctx->commandName;
        self::$commandResourceId = $ctx->commandResourceId;
        $commandName = $ctx->commandName;
        self::$taskResult = $ctx->execute(Task::named(
            'console.application.proof',
            static fn(): string => "task:$commandName",
        ));

        return 0;
    }
}

final class CoroutineRuntimeProbeCommand implements Scopeable
{
    public static bool $ran = false;

    public function __invoke(CommandContext $ctx): int
    {
        $ctx->delay(Mark::ms(1));
        self::$ran = true;

        return 0;
    }
}
