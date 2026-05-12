<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Application;

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Application\ConsoleConfig;
use Phalanx\Archon\Runtime\Identity\ArchonAnnotationSid;
use Phalanx\Archon\Runtime\Identity\ArchonResourceSid;
use Phalanx\Archon\Command\Opt;
use Phalanx\Archon\Tests\Fixtures\Commands\FlatRanCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\NestedRanCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\NoopCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\ScanCommand;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NestedArchonApplicationTest extends PhalanxTestCase
{
    #[Test]
    public function nestedCommandDispatchesToSubcommand(): void
    {
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

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            $code = $app->dispatch(['net', 'scan', '192.168.1.0/24']);

            self::assertSame(0, $code);
            self::assertSame('192.168.1.0/24', ScanCommand::$lastTarget);
        });

        $app->shutdown();
    }

    #[Test]
    public function flatAndNestedCoexist(): void
    {
        $commands = CommandGroup::of([
            'serve' => [FlatRanCommand::class, new CommandConfig(description: 'Start server')],
            'net' => CommandGroup::of([
                'probe' => NestedRanCommand::class,
            ]),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            $app->dispatch(['serve']);
            self::assertTrue(FlatRanCommand::$ran);
            self::assertFalse(NestedRanCommand::$ran);

            $app->dispatch(['net', 'probe']);
            self::assertTrue(NestedRanCommand::$ran);
        });

        $app->shutdown();
    }

    #[Test]
    public function groupWithoutSubcommandShowsHelp(): void
    {
        $stream = StreamOutputHelper::open();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
                'probe' => [NoopCommand::class, new CommandConfig(description: 'Probe host')],
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['net']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertStringContainsString('Network operations', $output);
        self::assertStringContainsString('scan', $output);
        self::assertStringContainsString('probe', $output);
        $app->shutdown();
    }

    #[Test]
    public function nestedGroupWithoutSubcommandShowsNestedHelp(): void
    {
        $stream = StreamOutputHelper::open();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'deep' => CommandGroup::of([
                    'scan' => [NoopCommand::class, new CommandConfig(description: 'Deep scan')],
                ], description: 'Deep network operations'),
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['net', 'deep']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertStringContainsString('Deep network operations', $output);
        self::assertStringContainsString('Usage:', $output);
        self::assertStringContainsString('net deep <command> [options]', $output);
        self::assertStringContainsString('scan', $output);
        $app->shutdown();
    }

    #[Test]
    public function directHelpForNestedGroupShowsNestedHelp(): void
    {
        $stream = StreamOutputHelper::open();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'deep' => CommandGroup::of([
                    'scan' => [NoopCommand::class, new CommandConfig(description: 'Deep scan')],
                ], description: 'Deep network operations'),
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['help', 'net', 'deep']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertStringContainsString('Deep network operations', $output);
        self::assertStringContainsString('Usage:', $output);
        self::assertStringContainsString('net deep <command> [options]', $output);
        $app->shutdown();
    }

    #[Test]
    public function directHelpForNestedCommandShowsFullCommandUsage(): void
    {
        $stream = StreamOutputHelper::open();
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
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['help', 'net', 'scan']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertStringContainsString('Scan network', $output);
        self::assertStringContainsString('Usage:', $output);
        self::assertStringContainsString('net scan <target>', $output);
        $app->shutdown();
    }

    #[Test]
    public function topLevelCommandHelpFlagShowsUsageWithoutRunningCommand(): void
    {
        $stream = StreamOutputHelper::open();
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
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['scan', '--help']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertNull(ScanCommand::$lastTarget);
        self::assertStringContainsString('Scan network', $output);
        self::assertStringContainsString('scan <target>', $output);
        self::assertSame(ManagedResourceState::Closed, $app->host()->runtime()->memory->resources->all(
            ArchonResourceSid::Command,
        )[0]->state);
        $app->shutdown();
    }

    #[Test]
    public function nestedCommandHelpFlagShowsUsageWithoutRunningCommand(): void
    {
        $stream = StreamOutputHelper::open();
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
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['net', 'scan', '--help']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertNull(ScanCommand::$lastTarget);
        self::assertStringContainsString('Scan network', $output);
        self::assertStringContainsString('net scan <target>', $output);
        self::assertSame(ManagedResourceState::Closed, $app->host()->runtime()->memory->resources->all(
            ArchonResourceSid::Command,
        )[0]->state);
        $app->shutdown();
    }

    #[Test]
    public function directCommandHelpAcceptsHelpSuffix(): void
    {
        $stream = StreamOutputHelper::open();
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
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['help', 'scan', '--help']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertStringContainsString('Scan network', $output);
        self::assertStringContainsString('scan <target>', $output);
        $app->shutdown();
    }

    #[Test]
    public function directHelpForUnknownNestedCommandFailsCommandResource(): void
    {
        $stream = StreamOutputHelper::open();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['help', 'net', 'missing']);
        $output = StreamOutputHelper::contents($stream);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown command: net missing', $output);
        self::assertSame(ManagedResourceState::Failed, $resource->state);
        self::assertSame('unknown_command', $app->host()->runtime()->memory->resources->annotations(
            $resource->id,
        )[ArchonAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function nestedUnknownCommandFailsWithFullCommandPath(): void
    {
        $stream = StreamOutputHelper::open();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['net', 'missing']);
        $output = StreamOutputHelper::contents($stream);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];
        $annotations = $app->host()->runtime()->memory->resources->annotations($resource->id);

        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown command: net missing', $output);
        self::assertSame(ManagedResourceState::Failed, $resource->state);
        self::assertSame('net missing', $annotations[ArchonAnnotationSid::CommandName->value()]);
        self::assertSame('unknown_command', $annotations[ArchonAnnotationSid::ErrorKind->value()]);
        $app->shutdown();
    }

    #[Test]
    public function nestedInvalidInputPrintsFullCommandPath(): void
    {
        $stream = StreamOutputHelper::open();
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
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['net', 'scan']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(1, $code);
        self::assertStringContainsString('Error:', $output);
        self::assertStringContainsString('Usage:', $output);
        self::assertStringContainsString('net scan <target>', $output);
        $app->shutdown();
    }

    #[Test]
    public function unexpectedNestedArgumentFailsWithFullCommandPath(): void
    {
        $stream = StreamOutputHelper::open();
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
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['net', 'scan', '192.168.1.0/24', 'extra']);
        $output = StreamOutputHelper::contents($stream);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(1, $code);
        self::assertNull(ScanCommand::$lastTarget);
        self::assertStringContainsString('Error: Unexpected argument: extra', $output);
        self::assertStringContainsString('net scan <target>', $output);
        self::assertSame(ManagedResourceState::Failed, $resource->state);
        $app->shutdown();
    }

    #[Test]
    public function flagOptionWithValueFailsBeforeCommandRuns(): void
    {
        $stream = StreamOutputHelper::open();
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
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['scan', '--detach=false', '192.168.1.0/24']);
        $output = StreamOutputHelper::contents($stream);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(1, $code);
        self::assertNull(ScanCommand::$lastTarget);
        self::assertStringContainsString('Error: Option --detach does not accept a value', $output);
        self::assertStringContainsString('scan <target> [options]', $output);
        self::assertSame(ManagedResourceState::Failed, $resource->state);
        $app->shutdown();
    }

    #[Test]
    public function groupHelpFlagShowsGroupHelpWithoutSubcommand(): void
    {
        $stream = StreamOutputHelper::open();
        $commands = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
                'probe' => [NoopCommand::class, new CommandConfig(description: 'Probe host')],
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['net', '--help']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertStringContainsString('Network operations', $output);
        self::assertStringContainsString('net <command>', $output);
        self::assertStringContainsString('scan', $output);
        self::assertStringContainsString('probe', $output);
        $app->shutdown();
    }

    #[Test]
    public function bareHelpSuffixOnFlatCommandShowsCommandHelp(): void
    {
        $stream = StreamOutputHelper::open();
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
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['scan', 'help']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertNull(ScanCommand::$lastTarget);
        self::assertStringContainsString('Scan network', $output);
        self::assertStringContainsString('scan <target>', $output);
        self::assertSame(ManagedResourceState::Closed, $app->host()->runtime()->memory->resources->all(
            ArchonResourceSid::Command,
        )[0]->state);
        $app->shutdown();
    }

    #[Test]
    public function bareHelpSuffixOnNestedCommandShowsCommandHelp(): void
    {
        $stream = StreamOutputHelper::open();
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
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['net', 'scan', 'help']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertNull(ScanCommand::$lastTarget);
        self::assertStringContainsString('Scan network', $output);
        self::assertStringContainsString('net scan <target>', $output);
        self::assertSame(ManagedResourceState::Closed, $app->host()->runtime()->memory->resources->all(
            ArchonResourceSid::Command,
        )[0]->state);
        $app->shutdown();
    }

    #[Test]
    public function helpForUnknownFlatCommandFails(): void
    {
        $stream = StreamOutputHelper::open();
        $commands = CommandGroup::of([
            'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['help', 'missing']);
        $output = StreamOutputHelper::contents($stream);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown command: missing', $output);
        self::assertSame(ManagedResourceState::Failed, $resource->state);
        $app->shutdown();
    }

    #[Test]
    public function bareHelpRendersTopLevelOverview(): void
    {
        $stream = StreamOutputHelper::open();
        $commands = CommandGroup::of([
            'serve' => [NoopCommand::class, new CommandConfig(description: 'Start server')],
            'net' => CommandGroup::of([
                'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan')],
            ], description: 'Network operations'),
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(output: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['help']);
        $output = StreamOutputHelper::contents($stream);

        self::assertSame(0, $code);
        self::assertStringContainsString('serve', $output);
        self::assertStringContainsString('Start server', $output);
        self::assertStringContainsString('net', $output);
        self::assertStringContainsString('Network operations', $output);
        $app->shutdown();
    }

    #[Test]
    public function helpWithExtraPositionalAfterCommandFails(): void
    {
        $stream = StreamOutputHelper::open();
        $commands = CommandGroup::of([
            'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan network')],
        ]);

        $app = Archon::starting()
            ->commands($commands)
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['help', 'scan', 'extra']);
        $output = StreamOutputHelper::contents($stream);
        $resource = $app->host()->runtime()->memory->resources->all(ArchonResourceSid::Command)[0];

        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown command: scan extra', $output);
        self::assertSame(ManagedResourceState::Failed, $resource->state);
        $app->shutdown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        ScanCommand::$lastTarget = null;
        FlatRanCommand::$ran = false;
        NestedRanCommand::$ran = false;
    }
}
