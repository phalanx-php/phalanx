<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration;

use Phalanx\Application;
use Phalanx\Console\Arg;
use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\CommandScope;
use Phalanx\Console\ConsoleRunner;
use Phalanx\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NestedConsoleRunnerTest extends AsyncTestCase
{
    #[Test]
    public function nested_command_dispatches_to_subcommand(): void
    {
        $this->runAsync(function (): void {
            $app = Application::starting()->compile();
            $received = null;

            $commands = CommandGroup::of([
                'net' => CommandGroup::of([
                    'scan' => new Command(
                        static function (CommandScope $scope) use (&$received): int {
                            $received = $scope->args->get('target');
                            return 0;
                        },
                        desc: 'Scan network',
                        args: [Arg::required('target', 'CIDR range')],
                    ),
                ], description: 'Network operations'),
            ]);

            $runner = ConsoleRunner::withHandlers($app, $commands);
            $code = $runner->run(['cli', 'net', 'scan', '192.168.1.0/24']);

            $this->assertSame(0, $code);
            $this->assertSame('192.168.1.0/24', $received);
        });
    }

    #[Test]
    public function flat_and_nested_coexist(): void
    {
        $this->runAsync(function (): void {
            $app = Application::starting()->compile();
            $flatRan = false;
            $nestedRan = false;

            $commands = CommandGroup::of([
                'serve' => new Command(
                    static function () use (&$flatRan): int {
                        $flatRan = true;
                        return 0;
                    },
                    desc: 'Start server',
                ),
                'net' => CommandGroup::of([
                    'probe' => new Command(
                        static function () use (&$nestedRan): int {
                            $nestedRan = true;
                            return 0;
                        },
                    ),
                ]),
            ]);

            $runner = ConsoleRunner::withHandlers($app, $commands);

            $runner->run(['cli', 'serve']);
            $this->assertTrue($flatRan);
            $this->assertFalse($nestedRan);

            $runner->run(['cli', 'net', 'probe']);
            $this->assertTrue($nestedRan);
        });
    }

    #[Test]
    public function group_without_subcommand_shows_help(): void
    {
        $app = Application::starting()->compile();

        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => new Command(static fn() => 0, desc: 'Scan network'),
                'probe' => new Command(static fn() => 0, desc: 'Probe host'),
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
