<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\HuggingFace;

use Phalanx\AiProviders\Provider\HuggingFace\Options;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    #[Test]
    public function defaultsAllNull(): void
    {
        $opts = new Options();

        self::assertNull($opts->temperature);
        self::assertNull($opts->topP);
        self::assertNull($opts->topK);
        self::assertNull($opts->maxNewTokens);
        self::assertNull($opts->doSample);
    }

    #[Test]
    public function explicitValuesPreserved(): void
    {
        $opts = new Options(
            temperature: 0.6,
            topP: 0.85,
            topK: 50,
            maxNewTokens: 1024,
            doSample: true,
        );

        self::assertSame(0.6, $opts->temperature);
        self::assertSame(0.85, $opts->topP);
        self::assertSame(50, $opts->topK);
        self::assertSame(1024, $opts->maxNewTokens);
        self::assertTrue($opts->doSample);
    }
}
