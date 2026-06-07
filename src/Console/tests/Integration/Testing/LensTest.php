<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration\Testing;

use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Testing\TestableBundle;
use Phalanx\Console\Tests\Fixtures\Commands\EchoArgvCommand;
use Phalanx\Console\Tests\Fixtures\Commands\FailingExitCommand;
use Phalanx\Console\Tests\Fixtures\Commands\NoopCommand;
use PHPUnit\Framework\Attributes\Test;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestApp;
use RuntimeException;

final class LensTest extends PhalanxTestCase
{
    #[Test]
    public function runDispatchesCommandAndCapturesStdout(): void
    {
        $app = $this->bootConsoleTestApp();

        $app->console
            ->commands(CommandGroup::of([
                'echo' => [EchoArgvCommand::class, new CommandConfig(description: 'Echo a fixed string.')],
            ]))
            ->run(['echo'])
            ->assertSuccessful()
            ->assertOutputContains('echoed: hello-from-command');
    }

    #[Test]
    public function runResultAssertsCommandLifecycleCleanup(): void
    {
        $app = $this->bootConsoleTestApp();

        $app->console
            ->commands(CommandGroup::of([
                'noop' => [NoopCommand::class, new CommandConfig(description: 'noop')],
            ]))
            ->run(['noop'])
            ->assertSuccessful()
            ->assertCommandResourcesClosed()
            ->assertNoLiveCommandResources()
            ->assertNoLiveRuntimeScopes()
            ->assertNoLiveTasks();
    }

    #[Test]
    public function runReportsNonZeroExitCode(): void
    {
        $app = $this->bootConsoleTestApp();

        $app->console
            ->commands(CommandGroup::of([
                'fail' => [FailingExitCommand::class, new CommandConfig(description: 'Exit non-zero.')],
            ]))
            ->run(['fail'])
            ->assertExitCode(7);
    }

    #[Test]
    public function runWithoutCommandsThrows(): void
    {
        $app = $this->bootConsoleTestApp();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('commands(CommandGroup) to be set first');

            $app->console->run(['anything']);
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function resetClearsCommandsBetweenRuns(): void
    {
        $app = $this->bootConsoleTestApp();

        $app->console->commands(CommandGroup::of([
            'noop' => [NoopCommand::class, new CommandConfig(description: 'noop')],
        ]));

        $app->reset();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('commands(CommandGroup) to be set first');

            $app->console->run(['noop']);
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function eachRunIsIsolated(): void
    {
        $app = $this->bootConsoleTestApp();

        $first = $app->console
            ->commands(CommandGroup::of([
                'echo' => [EchoArgvCommand::class, new CommandConfig(description: 'echo')],
            ]))
            ->run(['echo']);

        $second = $app->console
            ->commands(CommandGroup::of([
                'echo' => [EchoArgvCommand::class, new CommandConfig(description: 'echo')],
            ]))
            ->run(['echo']);

        $first->assertSuccessful()->assertOutputContains('echoed:');
        $second->assertSuccessful()->assertOutputContains('echoed:');
    }

    #[Test]
    public function assertOutputMatchesAcceptsRegex(): void
    {
        $app = $this->bootConsoleTestApp();

        $app->console
            ->commands(CommandGroup::of([
                'echo' => [EchoArgvCommand::class, new CommandConfig(description: 'echo')],
            ]))
            ->run(['echo'])
            ->assertOutputMatches('/^echoed: \\S+/m');
    }

    private function bootConsoleTestApp(): TestApp
    {
        return $this->testApp([], new TestableBundle());
    }
}
