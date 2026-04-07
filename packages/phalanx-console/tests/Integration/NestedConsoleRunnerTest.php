<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration;

use Phalanx\Application;
use Phalanx\Console\Arg;
use Phalanx\Console\CommandConfig;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\ConsoleRunner;
use Phalanx\Console\Tests\Fixtures\Commands\FlatRanCommand;
use Phalanx\Console\Tests\Fixtures\Commands\NestedRanCommand;
use Phalanx\Console\Tests\Fixtures\Commands\NoopCommand;
use Phalanx\Console\Tests\Fixtures\Commands\ScanCommand;
use Phalanx\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NestedConsoleRunnerTest extends AsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ScanCommand::$lastTarget = null;
        FlatRanCommand::$ran = false;
        NestedRanCommand::$ran = false;
    }

    #[Test]
    public function nested_command_dispatches_to_subcommand(): void
    {
        $this->runAsync(function (): void {
            $app = Application::starting()->compile();

            $commands = CommandGroup::of([
                'net' => CommandGroup::of([
                    'scan' => [
                        ScanCommand::class,
                        new CommandConfig(
                            description: 'Scan network',
                            arguments: [Arg::required('target', 'CIDR range')],
                        ),
                    ],
                ], description: 'Network operations'),
            ]);

            $runner = ConsoleRunner::withHandlers($app, $commands);
            $code = $runner->run(['cli', 'net', 'scan', '192.168.1.0/24']);

            $this->assertSame(0, $code);
            $this->assertSame('192.168.1.0/24', ScanCommand::$lastTarget);
        });
    }

    #[Test]
    public function flat_and_nested_coexist(): void
    {
        $this->runAsync(function (): void {
            $app = Application::starting()->compile();

            $commands = CommandGroup::of([
                'serve' => [FlatRanCommand::class, new CommandConfig(description: 'Start server')],
                'net' => CommandGroup::of([
                    'probe' => NestedRanCommand::class,
                ]),
            ]);

            $runner = ConsoleRunner::withHandlers($app, $commands);

            $runner->run(['cli', 'serve']);
            $this->assertTrue(FlatRanCommand::$ran);
            $this->assertFalse(NestedRanCommand::$ran);

            $runner->run(['cli', 'net', 'probe']);
            $this->assertTrue(NestedRanCommand::$ran);
        });
    }

    #[Test]
    public function group_without_subcommand_shows_help(): void
    {
        $app = Application::starting()->compile();

        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
                'probe' => [NoopCommand::class, new CommandConfig(description: 'Probe host')],
            ], description: 'Network operations'),
        ]);

        $runner = ConsoleRunner::withHandlers($app, $commands);

        ob_start();
        $code = $runner->run(['cli', 'net']);
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Network operations', $output);
        $this->assertStringContainsString('scan', $output);
        $this->assertStringContainsString('probe', $output);
    }
}
