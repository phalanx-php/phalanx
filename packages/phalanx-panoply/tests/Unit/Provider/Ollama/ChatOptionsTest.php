<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\Ollama;

use Phalanx\Panoply\Provider\Ollama\ChatOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatOptionsTest extends TestCase
{
    #[Test]
    public function defaultsAreAllNull(): void
    {
        $options = new ChatOptions();

        self::assertNull($options->temperature);
        self::assertNull($options->numPredict);
        self::assertNull($options->topP);
        self::assertSame([], $options->stop);
    }

    #[Test]
    public function namedArgConstruction(): void
    {
        $options = new ChatOptions(
            temperature: 0.8,
            numPredict: 512,
            topP: 0.95,
            stop: ['END'],
        );

        self::assertSame(0.8, $options->temperature);
        self::assertSame(512, $options->numPredict);
        self::assertSame(0.95, $options->topP);
        self::assertSame(['END'], $options->stop);
    }

    #[Test]
    public function partialOverrideKeepsOtherDefaults(): void
    {
        $options = new ChatOptions(numPredict: 256);

        self::assertNull($options->temperature);
        self::assertSame(256, $options->numPredict);
    }
}
