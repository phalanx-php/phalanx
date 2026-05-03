<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration;

use Phalanx\Archon\Archon;
use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\CommandScope;
use Phalanx\Archon\ConsoleConfig;
use Phalanx\Archon\Identity\ArchonAnnotationSid;
use Phalanx\Archon\Identity\ArchonResourceSid;
use Phalanx\Archon\Opt;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\Output\TerminalEnvironment;
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
        self::assertSame(ManagedResourceState::Closed, $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0]->state);
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
        $stream = self::outputStream();
        $app = Archon::command(
            'fail',
            static function (CommandScope $scope) use (&$disposed): int {
                $scope->onDispose(static function () use (&$disposed): void {
                    $disposed = true;
                });

                throw new \RuntimeException('expected failure');
            },
        )
            ->withConsoleConfig(new ConsoleConfig(errorOutput: self::streamOutput($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $stream): void {
            $code = $app->dispatch(['fail']);

            self::assertSame(1, $code);
            self::assertStringContainsString('Error: expected failure', self::streamContents($stream));
        });

        self::assertTrue($disposed);
        self::assertSame(ManagedResourceState::Failed, $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0]->state);
        PhalanxAssert::assertNoLiveTasks($app->host()->supervisor());
        $app->shutdown();
    }

    #[Test]
    public function unknownCommandReturnsNonZeroWithAvailableCommands(): void
    {
        $stream = self::outputStream();
        $app = Archon::command('known', static fn(CommandScope $scope): int => 0)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: self::streamOutput($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $stream): void {
            $code = $app->dispatch(['missing']);

            self::assertSame(1, $code);
            self::assertStringContainsString('Unknown command: missing', self::streamContents($stream));
            self::assertStringContainsString('known', self::streamContents($stream));
        });

        self::assertSame(ManagedResourceState::Failed, $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0]->state);
        self::assertSame('unknown_command', $app->host()->runtime()->memory->resources->annotations(
            $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0]->id,
        )[ArchonAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function helpForMissingCommandReturnsNonZero(): void
    {
        $stream = self::outputStream();
        $app = Archon::command('known', static fn(CommandScope $scope): int => 0)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: self::streamOutput($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $stream): void {
            $code = $app->dispatch(['help', 'missing']);

            self::assertSame(1, $code);
            self::assertStringContainsString('Unknown command: missing', self::streamContents($stream));
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
        $stream = self::outputStream();
        $missing = str_repeat('x', 400);
        $app = Archon::command('known', static fn(CommandScope $scope): int => 0)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: self::streamOutput($stream)))
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
        $stream = self::outputStream();
        $app = Archon::command(
            'cancel',
            static function (CommandScope $scope): int {
                throw new Cancelled('signal:int');
            },
        )
            ->withConsoleConfig(new ConsoleConfig(errorOutput: self::streamOutput($stream)))
            ->build();

        $this->runInCoroutine(static function () use ($app, $stream): void {
            self::assertSame(130, $app->dispatch(['cancel']));
            self::assertStringContainsString('Cancelled: signal:int', self::streamContents($stream));
        });

        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(ManagedResourceState::Aborted, $resource->state);
        self::assertSame('cancelled', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ArchonAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
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

    /** @return resource */
    private static function outputStream(): mixed
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            self::fail('Unable to open memory stream.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private static function streamOutput(mixed $stream): StreamOutput
    {
        return new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));
    }

    /** @param resource $stream */
    private static function streamContents(mixed $stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
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
