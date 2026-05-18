<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\OpenAI;

use Phalanx\Panoply\Provider\OpenAI\ChatOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatOptionsTest extends TestCase
{
    #[Test]
    public function defaultsAreAllNull(): void
    {
        $options = new ChatOptions();

        self::assertNull($options->maxTokens);
        self::assertNull($options->temperature);
        self::assertNull($options->topP);
        self::assertSame([], $options->stop);
        self::assertNull($options->seed);
    }

    #[Test]
    public function namedArgConstruction(): void
    {
        $options = new ChatOptions(
            maxTokens: 2048,
            temperature: 0.7,
            topP: 0.9,
            stop: ['END', 'STOP'],
            seed: 42,
        );

        self::assertSame(2048, $options->maxTokens);
        self::assertSame(0.7, $options->temperature);
        self::assertSame(0.9, $options->topP);
        self::assertSame(['END', 'STOP'], $options->stop);
        self::assertSame(42, $options->seed);
    }

    #[Test]
    public function partialOverrideKeepsOtherDefaults(): void
    {
        $options = new ChatOptions(temperature: 0.5);

        self::assertNull($options->maxTokens);
        self::assertSame(0.5, $options->temperature);
        self::assertNull($options->topP);
    }

    #[Test]
    public function propertyValuesAreAccessible(): void
    {
        $options = new ChatOptions(maxTokens: 1024);

        // private(set) enforces write protection — verify the property value is readable.
        self::assertSame(1024, $options->maxTokens);
    }
}
