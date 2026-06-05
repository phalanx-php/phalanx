<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\OpenAI;

use Phalanx\AiProviders\Provider\OpenAI\ResponsesOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponsesOptionsTest extends TestCase
{
    #[Test]
    public function defaultsAreAllNull(): void
    {
        $options = new ResponsesOptions();

        self::assertNull($options->maxOutputTokens);
        self::assertNull($options->temperature);
        self::assertNull($options->topP);
        self::assertNull($options->reasoningEffort);
    }

    #[Test]
    public function namedArgConstruction(): void
    {
        $options = new ResponsesOptions(
            maxOutputTokens: 2048,
            temperature: 0.7,
            topP: 0.9,
            reasoningEffort: 'high',
        );

        self::assertSame(2048, $options->maxOutputTokens);
        self::assertSame(0.7, $options->temperature);
        self::assertSame(0.9, $options->topP);
        self::assertSame('high', $options->reasoningEffort);
    }

    #[Test]
    public function partialOverrideKeepsOtherDefaults(): void
    {
        $options = new ResponsesOptions(reasoningEffort: 'medium');

        self::assertNull($options->maxOutputTokens);
        self::assertSame('medium', $options->reasoningEffort);
    }
}
