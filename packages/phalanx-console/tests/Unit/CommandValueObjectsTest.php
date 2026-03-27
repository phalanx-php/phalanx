<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit;

use Phalanx\Console\CommandArgs;
use Phalanx\Console\CommandOptions;
use Phalanx\Console\InvalidInputException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommandValueObjectsTest extends TestCase
{
    #[Test]
    public function args_get_returns_value_or_default(): void
    {
        $args = new CommandArgs(['image' => 'nginx', 'tag' => 'latest']);

        self::assertSame('nginx', $args->get('image'));
        self::assertSame('latest', $args->get('tag'));
        self::assertNull($args->get('missing'));
        self::assertSame('fallback', $args->get('missing', 'fallback'));
    }

    #[Test]
    public function args_has(): void
    {
        $args = new CommandArgs(['image' => 'nginx']);

        self::assertTrue($args->has('image'));
        self::assertFalse($args->has('missing'));
    }

    #[Test]
    public function args_required_throws_on_missing(): void
    {
        $args = new CommandArgs([]);

        $this->expectException(InvalidInputException::class);
        $args->required('image');
    }

    #[Test]
    public function args_required_returns_value(): void
    {
        $args = new CommandArgs(['image' => 'nginx']);

        self::assertSame('nginx', $args->required('image'));
    }

    #[Test]
    public function options_flag(): void
    {
        $options = new CommandOptions(['all' => true, 'verbose' => false]);

        self::assertTrue($options->flag('all'));
        self::assertFalse($options->flag('verbose'));
        self::assertFalse($options->flag('missing'));
    }

    #[Test]
    public function options_get_with_default(): void
    {
        $options = new CommandOptions(['format' => 'json']);

        self::assertSame('json', $options->get('format'));
        self::assertSame('table', $options->get('missing', 'table'));
    }

    #[Test]
    public function options_all(): void
    {
        $values = ['all' => true, 'format' => 'json'];
        $options = new CommandOptions($values);

        self::assertSame($values, $options->all());
    }
}
