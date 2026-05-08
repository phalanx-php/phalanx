<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Testing;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Testing\ArchonTestableBundle;
use Phalanx\Archon\Tests\Fixtures\Commands\EchoArgvCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\FailingExitCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\NoopCommand;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestApp;
use RuntimeException;

final class ConsoleLensTest extends PhalanxTestCase
{
    public function testRunDispatchesCommandAndCapturesStdout(): void
    {
        $app = $this->bootArchonTestApp();

        $app->console
            ->commands(CommandGroup::of([
                'echo' => [EchoArgvCommand::class, new CommandConfig(description: 'Echo a fixed string.')],
            ]))
            ->run(['echo'])
            ->assertSuccessful()
            ->assertOutputContains('echoed: hello-from-command');
    }

    public function testRunReportsNonZeroExitCode(): void
    {
        $app = $this->bootArchonTestApp();

        $app->console
            ->commands(CommandGroup::of([
                'fail' => [FailingExitCommand::class, new CommandConfig(description: 'Exit non-zero.')],
            ]))
            ->run(['fail'])
            ->assertExitCode(7);
    }

    public function testRunWithoutCommandsThrows(): void
    {
        $app = $this->bootArchonTestApp();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('commands(CommandGroup) to be set first');

            $app->console->run(['anything']);
        } finally {
            $app->shutdown();
        }
    }

    public function testResetClearsCommandsBetweenRuns(): void
    {
        $app = $this->bootArchonTestApp();

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

    public function testEachRunIsIsolated(): void
    {
        $app = $this->bootArchonTestApp();

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

    public function testAssertOutputMatchesAcceptsRegex(): void
    {
        $app = $this->bootArchonTestApp();

        $app->console
            ->commands(CommandGroup::of([
                'echo' => [EchoArgvCommand::class, new CommandConfig(description: 'echo')],
            ]))
            ->run(['echo'])
            ->assertOutputMatches('/^echoed: \\S+/m');
    }

    private function bootArchonTestApp(): TestApp
    {
        return $this->testApp([], new ArchonTestableBundle());
    }
}
