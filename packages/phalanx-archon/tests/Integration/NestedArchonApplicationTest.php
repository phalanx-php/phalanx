<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration;

use Phalanx\Archon\Archon;
use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\ConsoleConfig;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\Output\TerminalEnvironment;
use Phalanx\Archon\Tests\Fixtures\Commands\FlatRanCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\NestedRanCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\NoopCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\ScanCommand;
use Phalanx\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NestedArchonApplicationTest extends AsyncTestCase
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

            $app = Archon::starting()
                ->commands($commands)
                ->build();
            $code = $app->dispatch(['net', 'scan', '192.168.1.0/24']);

            $this->assertSame(0, $code);
            $this->assertSame('192.168.1.0/24', ScanCommand::$lastTarget);
            $app->shutdown();
        });
    }

    #[Test]
    public function flat_and_nested_coexist(): void
    {
        $this->runAsync(function (): void {
            $commands = CommandGroup::of([
                'serve' => [FlatRanCommand::class, new CommandConfig(description: 'Start server')],
                'net' => CommandGroup::of([
                    'probe' => NestedRanCommand::class,
                ]),
            ]);

            $app = Archon::starting()
                ->commands($commands)
                ->build();

            $app->dispatch(['serve']);
            $this->assertTrue(FlatRanCommand::$ran);
            $this->assertFalse(NestedRanCommand::$ran);

            $app->dispatch(['net', 'probe']);
            $this->assertTrue(NestedRanCommand::$ran);
            $app->shutdown();
        });
    }

    #[Test]
    public function group_without_subcommand_shows_help(): void
    {
        $stream = self::outputStream();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
                'probe' => [NoopCommand::class, new CommandConfig(description: 'Probe host')],
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: self::streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['net']);
        $output = self::streamContents($stream);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Network operations', $output);
        $this->assertStringContainsString('scan', $output);
        $this->assertStringContainsString('probe', $output);
        $app->shutdown();
    }

    #[Test]
    public function nested_group_without_subcommand_shows_nested_help(): void
    {
        $stream = self::outputStream();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'deep' => CommandGroup::of([
                    'scan' => [NoopCommand::class, new CommandConfig(description: 'Deep scan')],
                ], description: 'Deep network operations'),
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: self::streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['net', 'deep']);
        $output = self::streamContents($stream);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Deep network operations', $output);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('net deep <command> [options]', $output);
        $this->assertStringContainsString('scan', $output);
        $app->shutdown();
    }

    #[Test]
    public function nested_invalid_input_printsFullCommandPath(): void
    {
        $stream = self::outputStream();
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

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: self::streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['net', 'scan']);
        $output = self::streamContents($stream);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Error:', $output);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('net scan <target>', $output);
        $app->shutdown();
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
