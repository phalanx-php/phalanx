<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit;

use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\HelpGenerator;
use Phalanx\Scope;
use PHPUnit\Framework\TestCase;

final class NestedHelpGeneratorTest extends TestCase
{
    public function test_group_help_lists_commands(): void
    {
        $group = CommandGroup::of([
            'scan' => new Command(static fn(Scope $s) => 0, desc: 'Scan a subnet'),
            'probe' => new Command(static fn(Scope $s) => 0, desc: 'Probe a host'),
        ], description: 'Network operations');

        $help = HelpGenerator::forGroup('net', $group);

        $this->assertStringContainsString('Network operations', $help);
        $this->assertStringContainsString('net <command>', $help);
        $this->assertStringContainsString('scan', $help);
        $this->assertStringContainsString('Scan a subnet', $help);
        $this->assertStringContainsString('probe', $help);
        $this->assertStringContainsString('Probe a host', $help);
    }

    public function test_top_level_help_separates_groups_and_commands(): void
    {
        $root = CommandGroup::of([
            'serve' => new Command(static fn(Scope $s) => 0, desc: 'Start server'),
            'net' => CommandGroup::of([
                'scan' => new Command(static fn(Scope $s) => 0),
            ], description: 'Network operations'),
        ], description: 'myapp');

        $help = HelpGenerator::forTopLevel($root);

        $this->assertStringContainsString('Commands:', $help);
        $this->assertStringContainsString('serve', $help);
        $this->assertStringContainsString('Groups:', $help);
        $this->assertStringContainsString('net', $help);
        $this->assertStringContainsString('Network operations', $help);
    }

    public function test_group_help_with_nested_subgroups(): void
    {
        $group = CommandGroup::of([
            'scan' => new Command(static fn(Scope $s) => 0, desc: 'Scan'),
            'deep' => CommandGroup::of([
                'inner' => new Command(static fn(Scope $s) => 0),
            ], description: 'Deeper group'),
        ], description: 'Outer');

        $help = HelpGenerator::forGroup('test', $group);

        $this->assertStringContainsString('Commands:', $help);
        $this->assertStringContainsString('Groups:', $help);
        $this->assertStringContainsString('deep', $help);
        $this->assertStringContainsString('Deeper group', $help);
    }
}
