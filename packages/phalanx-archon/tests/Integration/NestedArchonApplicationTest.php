<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration;

use Phalanx\Archon\Archon;
use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\ConsoleConfig;
use Phalanx\Archon\Identity\ArchonAnnotationSid;
use Phalanx\Archon\Identity\ArchonResourceSid;
use Phalanx\Archon\Opt;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\Output\TerminalEnvironment;
use Phalanx\Archon\Tests\Fixtures\Commands\FlatRanCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\NestedRanCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\NoopCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\ScanCommand;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NestedArchonApplicationTest extends AsyncTestCase
{
    #[Test]
    public function nestedCommandDispatchesToSubcommand(): void
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
    public function flatAndNestedCoexist(): void
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
    public function groupWithoutSubcommandShowsHelp(): void
    {
        $stream = $this->outputStream();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
                'probe' => [NoopCommand::class, new CommandConfig(description: 'Probe host')],
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['net']);
        $output = $this->streamContents($stream);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Network operations', $output);
        $this->assertStringContainsString('scan', $output);
        $this->assertStringContainsString('probe', $output);
        $app->shutdown();
    }

    #[Test]
    public function nestedGroupWithoutSubcommandShowsNestedHelp(): void
    {
        $stream = $this->outputStream();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'deep' => CommandGroup::of([
                    'scan' => [NoopCommand::class, new CommandConfig(description: 'Deep scan')],
                ], description: 'Deep network operations'),
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['net', 'deep']);
        $output = $this->streamContents($stream);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Deep network operations', $output);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('net deep <command> [options]', $output);
        $this->assertStringContainsString('scan', $output);
        $app->shutdown();
    }

    #[Test]
    public function directHelpForNestedGroupShowsNestedHelp(): void
    {
        $stream = $this->outputStream();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'deep' => CommandGroup::of([
                    'scan' => [NoopCommand::class, new CommandConfig(description: 'Deep scan')],
                ], description: 'Deep network operations'),
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['help', 'net', 'deep']);
        $output = $this->streamContents($stream);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Deep network operations', $output);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('net deep <command> [options]', $output);
        $app->shutdown();
    }

    #[Test]
    public function directHelpForNestedCommandShowsFullCommandUsage(): void
    {
        $stream = $this->outputStream();
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
            ->withConsoleConfig(new ConsoleConfig(output: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['help', 'net', 'scan']);
        $output = $this->streamContents($stream);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Scan network', $output);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('net scan <target>', $output);
        $app->shutdown();
    }

    #[Test]
    public function topLevelCommandHelpFlagShowsUsageWithoutRunningCommand(): void
    {
        $stream = $this->outputStream();
        $commands = CommandGroup::of([
            'scan' => [
                ScanCommand::class,
                new CommandConfig(
                    description: 'Scan network',
                    arguments: [Arg::required('target', 'CIDR range')],
                ),
            ],
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['scan', '--help']);
        $output = $this->streamContents($stream);

        $this->assertSame(0, $code);
        $this->assertNull(ScanCommand::$lastTarget);
        $this->assertStringContainsString('Scan network', $output);
        $this->assertStringContainsString('scan <target>', $output);
        $this->assertSame(ManagedResourceState::Closed, $app->host()->runtime()->memory->resources->all(
            ArchonResourceSid::Command,
        )[0]->state);
        $app->shutdown();
    }

    #[Test]
    public function nestedCommandHelpFlagShowsUsageWithoutRunningCommand(): void
    {
        $stream = $this->outputStream();
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
            ->withConsoleConfig(new ConsoleConfig(output: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['net', 'scan', '--help']);
        $output = $this->streamContents($stream);

        $this->assertSame(0, $code);
        $this->assertNull(ScanCommand::$lastTarget);
        $this->assertStringContainsString('Scan network', $output);
        $this->assertStringContainsString('net scan <target>', $output);
        $this->assertSame(ManagedResourceState::Closed, $app->host()->runtime()->memory->resources->all(
            ArchonResourceSid::Command,
        )[0]->state);
        $app->shutdown();
    }

    #[Test]
    public function directCommandHelpAcceptsHelpSuffix(): void
    {
        $stream = $this->outputStream();
        $commands = CommandGroup::of([
            'scan' => [
                ScanCommand::class,
                new CommandConfig(
                    description: 'Scan network',
                    arguments: [Arg::required('target', 'CIDR range')],
                ),
            ],
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['help', 'scan', '--help']);
        $output = $this->streamContents($stream);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Scan network', $output);
        $this->assertStringContainsString('scan <target>', $output);
        $app->shutdown();
    }

    #[Test]
    public function directHelpForUnknownNestedCommandFailsCommandResource(): void
    {
        $stream = $this->outputStream();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['help', 'net', 'missing']);
        $output = $this->streamContents($stream);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown command: net missing', $output);
        $this->assertSame(ManagedResourceState::Failed, $resource->state);
        $this->assertSame('unknown_command', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ArchonAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function nestedUnknownCommandFailsWithFullCommandPath(): void
    {
        $stream = $this->outputStream();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['net', 'missing']);
        $output = $this->streamContents($stream);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];
        $annotations = $app->host()->runtime()->memory->resources->annotations($resource->id);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown command: net missing', $output);
        $this->assertSame(ManagedResourceState::Failed, $resource->state);
        $this->assertSame('net missing', $annotations[ArchonAnnotationSid::CommandName->value()]);
        $this->assertSame('unknown_command', $annotations[ArchonAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function nestedInvalidInputPrintsFullCommandPath(): void
    {
        $stream = $this->outputStream();
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
            ->withConsoleConfig(new ConsoleConfig(errorOutput: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['net', 'scan']);
        $output = $this->streamContents($stream);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Error:', $output);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('net scan <target>', $output);
        $app->shutdown();
    }

    #[Test]
    public function unexpectedNestedArgumentFailsWithFullCommandPath(): void
    {
        $stream = $this->outputStream();
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
            ->withConsoleConfig(new ConsoleConfig(errorOutput: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['net', 'scan', '192.168.1.0/24', 'extra']);
        $output = $this->streamContents($stream);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        $this->assertSame(1, $code);
        $this->assertNull(ScanCommand::$lastTarget);
        $this->assertStringContainsString('Error: Unexpected argument: extra', $output);
        $this->assertStringContainsString('net scan <target>', $output);
        $this->assertSame(ManagedResourceState::Failed, $resource->state);
        $app->shutdown();
    }

    #[Test]
    public function flagOptionWithValueFailsBeforeCommandRuns(): void
    {
        $stream = $this->outputStream();
        $commands = CommandGroup::of([
            'scan' => [
                ScanCommand::class,
                new CommandConfig(
                    description: 'Scan network',
                    arguments: [Arg::required('target', 'CIDR range')],
                    options: [Opt::flag('detach')],
                ),
            ],
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: $this->streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['scan', '--detach=false', '192.168.1.0/24']);
        $output = $this->streamContents($stream);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        $this->assertSame(1, $code);
        $this->assertNull(ScanCommand::$lastTarget);
        $this->assertStringContainsString('Error: Option --detach does not accept a value', $output);
        $this->assertStringContainsString('scan <target> [options]', $output);
        $this->assertSame(ManagedResourceState::Failed, $resource->state);
        $app->shutdown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        ScanCommand::$lastTarget = null;
        FlatRanCommand::$ran = false;
        NestedRanCommand::$ran = false;
    }

    /** @return resource */
    private function outputStream(): mixed
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            self::fail('Unable to open memory stream.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private function streamOutput(mixed $stream): StreamOutput
    {
        return new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));
    }

    /** @param resource $stream */
    private function streamContents(mixed $stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }
}
