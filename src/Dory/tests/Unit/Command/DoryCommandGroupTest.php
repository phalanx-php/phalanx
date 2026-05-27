<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit\Command;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Dory\Command\DoctorCommand;
use Phalanx\Dory\Command\DoryCommandGroup;
use Phalanx\Dory\Command\InitCommand;
use Phalanx\Dory\Command\RunCommand;
use Phalanx\Dory\Command\ServeCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DoryCommandGroupTest extends TestCase
{
    #[Test]
    public function registers_run_command(): void
    {
        $group = DoryCommandGroup::commands();
        $commands = $group->commands();

        self::assertArrayHasKey('run', $commands);
        self::assertSame(RunCommand::class, $commands['run']->task);
    }

    #[Test]
    public function registers_init_command(): void
    {
        $group = DoryCommandGroup::commands();
        $commands = $group->commands();

        self::assertArrayHasKey('init', $commands);
        self::assertSame(InitCommand::class, $commands['init']->task);
    }

    #[Test]
    public function registers_doctor_command(): void
    {
        $group = DoryCommandGroup::commands();
        $commands = $group->commands();

        self::assertArrayHasKey('doctor', $commands);
        self::assertSame(DoctorCommand::class, $commands['doctor']->task);
    }

    #[Test]
    public function registers_serve_when_skopos_available(): void
    {
        if (!class_exists(\Phalanx\Skopos\FileWatcher::class)) {
            self::markTestSkipped('Skopos not available in this environment.');
        }

        $group = DoryCommandGroup::commands();
        $commands = $group->commands();

        self::assertArrayHasKey('serve', $commands);
        self::assertSame(ServeCommand::class, $commands['serve']->task);
    }

    #[Test]
    public function run_has_required_script_argument(): void
    {
        $group = DoryCommandGroup::commands();
        $commands = $group->commands();

        $config = $commands['run']->config;
        self::assertInstanceOf(CommandConfig::class, $config);

        $arguments = $config->arguments;
        self::assertCount(1, $arguments);
        self::assertSame('script', $arguments[0]->name);
        self::assertTrue($arguments[0]->required);
    }

    #[Test]
    public function init_has_optional_directory_argument(): void
    {
        $group = DoryCommandGroup::commands();
        $commands = $group->commands();

        $config = $commands['init']->config;
        self::assertInstanceOf(CommandConfig::class, $config);

        $arguments = $config->arguments;
        self::assertCount(1, $arguments);
        self::assertSame('directory', $arguments[0]->name);
        self::assertFalse($arguments[0]->required);
        self::assertSame('.', $arguments[0]->default);
    }

    #[Test]
    public function doctor_has_no_arguments(): void
    {
        $group = DoryCommandGroup::commands();
        $commands = $group->commands();

        $config = $commands['doctor']->config;
        self::assertInstanceOf(CommandConfig::class, $config);
        self::assertCount(0, $config->arguments);
    }

    #[Test]
    public function all_core_commands_present_in_keys(): void
    {
        $group = DoryCommandGroup::commands();
        $keys = $group->keys();

        self::assertContains('run', $keys);
        self::assertContains('init', $keys);
        self::assertContains('doctor', $keys);
    }
}
