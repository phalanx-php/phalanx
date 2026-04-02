<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit;

use Phalanx\Console\Arg;
use Phalanx\Console\CommandConfig;
use Phalanx\Console\HelpGenerator;
use Phalanx\Console\Opt;
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
}
