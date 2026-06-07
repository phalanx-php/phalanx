<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Command;

use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Tests\Fixtures\Commands\FlatRanCommand;
use Phalanx\Console\Tests\Fixtures\Commands\ScanCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NestedCommandGroupTest extends TestCase
{
    #[Test]
    public function keys_includes_groups_and_commands(): void
    {
        $group = CommandGroup::of([
            'serve' => FlatRanCommand::class,
            'net' => CommandGroup::of([
                'scan' => ScanCommand::class,
            ], description: 'Network operations'),
        ]);

        $keys = $group->keys();

        self::assertContains('serve', $keys);
        self::assertContains('net', $keys);
    }

    #[Test]
    public function is_group_distinguishes_groups_from_commands(): void
    {
        $group = CommandGroup::of([
            'serve' => FlatRanCommand::class,
            'net' => CommandGroup::of([
                'scan' => ScanCommand::class,
            ]),
        ]);

        self::assertTrue($group->isGroup('net'));
        self::assertFalse($group->isGroup('serve'));
        self::assertFalse($group->isGroup('nonexistent'));
    }

    #[Test]
    public function group_returns_nested_group(): void
    {
        $inner = CommandGroup::of([
            'scan' => ScanCommand::class,
        ], description: 'Network ops');

        $root = CommandGroup::of([
            'net' => $inner,
        ]);

        $resolved = $root->group('net');

        self::assertNotNull($resolved);
        self::assertSame('Network ops', $resolved->description());
        self::assertContains('scan', $resolved->keys());
    }

    #[Test]
    public function group_returns_null_for_nonexistent(): void
    {
        $group = CommandGroup::of([
            'serve' => FlatRanCommand::class,
        ]);

        self::assertNull($group->group('nonexistent'));
    }

    #[Test]
    public function description_stored(): void
    {
        $group = CommandGroup::of([], description: 'My application');

        self::assertSame('My application', $group->description());
    }

    #[Test]
    public function merge_preserves_groups(): void
    {
        $a = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => ScanCommand::class,
            ]),
        ]);

        $b = CommandGroup::of([
            'ssh' => CommandGroup::of([
                'run' => FlatRanCommand::class,
            ]),
        ]);

        $merged = $a->merge($b);

        self::assertTrue($merged->isGroup('net'));
        self::assertTrue($merged->isGroup('ssh'));
    }

    #[Test]
    public function commands_excludes_groups(): void
    {
        $group = CommandGroup::of([
            'serve' => FlatRanCommand::class,
            'net' => CommandGroup::of([
                'scan' => ScanCommand::class,
            ]),
        ]);

        $commands = $group->commands();

        self::assertArrayHasKey('serve', $commands);
        self::assertArrayNotHasKey('net', $commands);
    }
}
