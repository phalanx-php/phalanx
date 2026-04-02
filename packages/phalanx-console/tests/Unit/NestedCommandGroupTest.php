<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit;

use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Scope;
use PHPUnit\Framework\TestCase;

final class NestedCommandGroupTest extends TestCase
{
    public function test_keys_includes_groups_and_commands(): void
    {
        $group = CommandGroup::of([
            'serve' => new Command(static fn(Scope $s) => 0, desc: 'Start server'),
            'net' => CommandGroup::of([
                'scan' => new Command(static fn(Scope $s) => 0, desc: 'Scan network'),
            ], description: 'Network operations'),
        ]);

        $keys = $group->keys();

        $this->assertContains('serve', $keys);
        $this->assertContains('net', $keys);
    }

    public function test_is_group_distinguishes_groups_from_commands(): void
    {
        $group = CommandGroup::of([
            'serve' => new Command(static fn(Scope $s) => 0),
            'net' => CommandGroup::of([
                'scan' => new Command(static fn(Scope $s) => 0),
            ]),
        ]);

        $this->assertTrue($group->isGroup('net'));
        $this->assertFalse($group->isGroup('serve'));
        $this->assertFalse($group->isGroup('nonexistent'));
    }

    public function test_group_returns_nested_group(): void
    {
        $inner = CommandGroup::of([
            'scan' => new Command(static fn(Scope $s) => 0, desc: 'Scan'),
        ], description: 'Network ops');

        $root = CommandGroup::of([
            'net' => $inner,
        ]);

        $resolved = $root->group('net');

        $this->assertNotNull($resolved);
        $this->assertSame('Network ops', $resolved->description());
        $this->assertContains('scan', $resolved->keys());
    }

    public function test_group_returns_null_for_nonexistent(): void
    {
        $group = CommandGroup::of([
            'serve' => new Command(static fn(Scope $s) => 0),
        ]);

        $this->assertNull($group->group('nonexistent'));
    }

    public function test_description_stored(): void
    {
        $group = CommandGroup::of([], description: 'My application');

        $this->assertSame('My application', $group->description());
    }

    public function test_merge_preserves_groups(): void
    {
        $a = CommandGroup::of([
            'net' => CommandGroup::of([
                'scan' => new Command(static fn(Scope $s) => 0),
            ]),
        ]);

        $b = CommandGroup::of([
            'ssh' => CommandGroup::of([
                'run' => new Command(static fn(Scope $s) => 0),
            ]),
        ]);

        $merged = $a->merge($b);

        $this->assertTrue($merged->isGroup('net'));
        $this->assertTrue($merged->isGroup('ssh'));
    }

    public function test_commands_excludes_groups(): void
    {
        $group = CommandGroup::of([
            'serve' => new Command(static fn(Scope $s) => 0, desc: 'Start server'),
            'net' => CommandGroup::of([
                'scan' => new Command(static fn(Scope $s) => 0),
            ]),
        ]);

        $commands = $group->commands();

        $this->assertArrayHasKey('serve', $commands);
        $this->assertArrayNotHasKey('net', $commands);
    }
}
