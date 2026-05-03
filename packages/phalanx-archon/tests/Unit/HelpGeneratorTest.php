<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit;

use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\HelpGenerator;
use Phalanx\Archon\Opt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HelpGeneratorTest extends TestCase
{
    #[Test]
    public function generates_usage_with_arguments(): void
    {
        $config = new CommandConfig(
            description: 'Create a container',
            arguments: [
                Arg::required('image', 'Docker image'),
                Arg::optional('tag', 'Image tag', default: 'latest'),
            ],
        );

        $help = HelpGenerator::forCommand('up', $config);

        self::assertStringContainsString('Create a container', $help);
        self::assertStringContainsString('up <image> [tag]', $help);
        self::assertStringContainsString('Docker image', $help);
        self::assertStringContainsString('(default: latest)', $help);
    }

    #[Test]
    public function generates_options_section(): void
    {
        $config = new CommandConfig(
            description: 'List containers',
            options: [
                Opt::flag('all', 'a', 'Show all containers'),
                Opt::value('format', 'f', 'Output format', default: 'table'),
            ],
        );

        $help = HelpGenerator::forCommand('ps', $config);

        self::assertStringContainsString('-a, --all', $help);
        self::assertStringContainsString('-f, --format=<value>', $help);
        self::assertStringContainsString('(default: table)', $help);
    }

    #[Test]
    public function minimal_config_produces_usage(): void
    {
        $config = new CommandConfig();
        $help = HelpGenerator::forCommand('run', $config);

        self::assertStringContainsString('run', $help);
    }

    #[Test]
    public function command_with_options_only_omits_arguments_section(): void
    {
        $config = new CommandConfig(
            options: [Opt::flag('verbose', 'v', 'Verbose output')],
        );

        $help = HelpGenerator::forCommand('serve', $config);

        self::assertStringContainsString('Options:', $help);
        self::assertStringContainsString('[options]', $help);
        self::assertStringNotContainsString('Arguments:', $help);
    }

    #[Test]
    public function command_with_arguments_only_omits_options_section(): void
    {
        $config = new CommandConfig(
            arguments: [Arg::required('image', 'Docker image')],
        );

        $help = HelpGenerator::forCommand('pull', $config);

        self::assertStringContainsString('Arguments:', $help);
        self::assertStringNotContainsString('Options:', $help);
        self::assertStringNotContainsString('[options]', $help);
    }

    #[Test]
    public function option_without_shorthand_renders_long_form_only(): void
    {
        $config = new CommandConfig(
            options: [Opt::value('format', desc: 'Output format')],
        );

        $help = HelpGenerator::forCommand('ps', $config);

        self::assertStringContainsString('--format=<value>', $help);
        self::assertStringNotContainsString(', --format', $help);
    }
}
