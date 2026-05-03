<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration;

use Phalanx\Application;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\CommandScope;
use Phalanx\Archon\ConsoleRunner;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Testing\Assert as PhalanxAssert;
use Phalanx\Tests\Support\CoroutineTestCase;

final class ArchonConsoleRunnerTest extends CoroutineTestCase
{
    public function testCommandRunsInsideAegisArchonScope(): void
    {
        $app = Application::starting()->compile();
        $runner = ConsoleRunner::withHandlers($app, CommandGroup::of([
            'probe' => ArchonProbeCommand::class,
        ]));

        $this->runInCoroutine(static function () use ($runner): void {
            self::assertSame(0, $runner->run(['cli', 'probe']));
        });

        self::assertTrue(ArchonProbeCommand::$receivedCommandScope);
        self::assertSame('probe', ArchonProbeCommand::$commandName);
        self::assertSame('task:probe', ArchonProbeCommand::$taskResult);
        PhalanxAssert::assertNoLiveTasks($app->supervisor());
    }

    protected function setUp(): void
    {
        parent::setUp();

        ArchonProbeCommand::$receivedCommandScope = false;
        ArchonProbeCommand::$commandName = null;
        ArchonProbeCommand::$taskResult = null;
    }
}

final class ArchonProbeCommand implements Scopeable
{
    public static bool $receivedCommandScope = false;

    public static ?string $commandName = null;

    public static ?string $taskResult = null;

    public function __invoke(CommandScope $scope): int
    {
        self::$receivedCommandScope = $scope instanceof CommandScope;
        self::$commandName = $scope->commandName;
        self::$taskResult = $scope->execute(Task::named(
            'archon.proof',
            static fn(ExecutionScope $taskScope): string => 'task:' . $taskScope->attribute('command'),
        ));

        return 0;
    }
}
