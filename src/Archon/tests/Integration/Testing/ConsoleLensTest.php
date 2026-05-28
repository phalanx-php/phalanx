<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Testing;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Testing\ArchonTestableBundle;
use Phalanx\Archon\Tests\Fixtures\Commands\EchoArgvCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\FailingExitCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\NoopCommand;
use PHPUnit\Framework\Attributes\Test;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestApp;
use RuntimeException;

final class ConsoleLensTest extends PhalanxTestCase
{
    #[Test]
    public function runDispatchesCommandAndCapturesStdout(): void
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

    #[Test]
    public function runReportsNonZeroExitCode(): void
    {
        $app = $this->bootArchonTestApp();

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
        $app = $this->bootArchonTestApp();

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

    #[Test]
    public function eachRunIsIsolated(): void
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

    #[Test]
    public function assertOutputMatchesAcceptsRegex(): void
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
