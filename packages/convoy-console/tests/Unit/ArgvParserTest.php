<?php

declare(strict_types=1);

namespace Convoy\Console\Tests\Unit;

use Convoy\Console\ArgvParser;
use Convoy\Console\CommandConfig;
use Convoy\Console\InvalidInputException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArgvParserTest extends TestCase
{
    #[Test]
    public function parses_positional_arguments(): void
    {
        $config = new CommandConfig()
            ->withArgument('image', 'Docker image', required: true)
            ->withArgument('tag', 'Image tag', required: false, default: 'latest');

        $input = ArgvParser::parse(['nginx'], $config);

        self::assertSame('nginx', $input->args->get('image'));
        self::assertSame('latest', $input->args->get('tag'));
    }

    #[Test]
    public function parses_long_option_with_equals(): void
    {
        $config = new CommandConfig()
            ->withArgument('image')
            ->withOption('name', shorthand: 'n', requiresValue: true);

        $input = ArgvParser::parse(['nginx', '--name=convoy-test'], $config);

        self::assertSame('nginx', $input->args->get('image'));
        self::assertSame('convoy-test', $input->options->get('name'));
    }

    #[Test]
    public function parses_long_option_with_space(): void
    {
        $config = new CommandConfig()
            ->withOption('name', requiresValue: true);

        $input = ArgvParser::parse(['--name', 'convoy-test'], $config);

        self::assertSame('convoy-test', $input->options->get('name'));
    }

    #[Test]
    public function parses_boolean_flag(): void
    {
        $config = new CommandConfig()
            ->withOption('all', shorthand: 'a', description: 'Show all');

        $input = ArgvParser::parse(['--all'], $config);

        self::assertTrue($input->options->flag('all'));
    }

    #[Test]
    public function parses_shorthand_flag(): void
    {
        $config = new CommandConfig()
            ->withOption('all', shorthand: 'a');

        $input = ArgvParser::parse(['-a'], $config);

        self::assertTrue($input->options->flag('all'));
    }

    #[Test]
    public function parses_shorthand_with_value(): void
    {
        $config = new CommandConfig()
            ->withOption('name', shorthand: 'n', requiresValue: true);

        $input = ArgvParser::parse(['-n', 'test'], $config);

        self::assertSame('test', $input->options->get('name'));
    }

    #[Test]
    public function double_dash_stops_option_parsing(): void
    {
        $config = new CommandConfig()
            ->withArgument('file')
            ->withOption('verbose', shorthand: 'v');

        $input = ArgvParser::parse(['--', '--verbose'], $config);

        self::assertSame('--verbose', $input->args->get('file'));
        self::assertFalse($input->options->flag('verbose'));
    }

    #[Test]
    public function throws_on_missing_required_argument(): void
    {
        $config = new CommandConfig()
            ->withArgument('image', 'Docker image', required: true);

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Missing required argument: image');

        ArgvParser::parse([], $config);
    }

    #[Test]
    public function throws_on_unknown_option(): void
    {
        $config = new CommandConfig();

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Unknown option: --foo');

        ArgvParser::parse(['--foo'], $config);
    }

    #[Test]
    public function throws_on_missing_option_value(): void
    {
        $config = new CommandConfig()
            ->withOption('name', requiresValue: true);

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Option --name requires a value');

        ArgvParser::parse(['--name'], $config);
    }

    #[Test]
    public function applies_option_defaults(): void
    {
        $config = new CommandConfig()
            ->withOption('format', requiresValue: true, default: 'json');

        $input = ArgvParser::parse([], $config);

        self::assertSame('json', $input->options->get('format'));
    }

    #[Test]
    public function mixed_positional_and_options(): void
    {
        $config = new CommandConfig()
            ->withArgument('image', required: true)
            ->withOption('name', shorthand: 'n', requiresValue: true)
            ->withOption('detach', shorthand: 'd');

        $input = ArgvParser::parse(['nginx', '--name=web', '-d'], $config);

        self::assertSame('nginx', $input->args->get('image'));
        self::assertSame('web', $input->options->get('name'));
        self::assertTrue($input->options->flag('detach'));
    }

    #[Test]
    public function empty_config_parses_no_args(): void
    {
        $input = ArgvParser::parse([], new CommandConfig());

        self::assertSame([], $input->args->all());
        self::assertSame([], $input->options->all());
    }

    #[Test]
    public function exception_carries_config(): void
    {
        $config = new CommandConfig(description: 'Test command')
            ->withArgument('image', required: true);

        try {
            ArgvParser::parse([], $config);
            self::fail('Expected InvalidInputException');
        } catch (InvalidInputException $e) {
            self::assertNotNull($e->config);
            self::assertSame('Test command', $e->config->description);
        }
    }
}
