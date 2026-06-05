<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\Gemini;

use Phalanx\AiProviders\Provider\Gemini\Options;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    #[Test]
    public function defaultsAllNull(): void
    {
        $opts = new Options();

        self::assertNull($opts->maxOutputTokens);
        self::assertNull($opts->temperature);
        self::assertNull($opts->topP);
        self::assertNull($opts->topK);
        self::assertSame([], $opts->stopSequences);
        self::assertNull($opts->thinkingBudget);
    }

    #[Test]
    public function explicitValuesPreserved(): void
    {
        $opts = new Options(
            maxOutputTokens: 2048,
            temperature: 0.7,
            topP: 0.9,
            topK: 40,
            stopSequences: ['###', '---'],
            thinkingBudget: 'medium',
        );

        self::assertSame(2048, $opts->maxOutputTokens);
        self::assertSame(0.7, $opts->temperature);
        self::assertSame(0.9, $opts->topP);
        self::assertSame(40, $opts->topK);
        self::assertSame(['###', '---'], $opts->stopSequences);
        self::assertSame('medium', $opts->thinkingBudget);
    }

    #[Test]
    public function thinkingBudgetAcceptsAllValidValues(): void
    {
        foreach (['low', 'medium', 'high'] as $budget) {
            $opts = new Options(thinkingBudget: $budget);
            self::assertSame($budget, $opts->thinkingBudget);
        }
    }
}
