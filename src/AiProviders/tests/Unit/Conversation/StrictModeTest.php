<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Conversation;

use Phalanx\AiProviders\Conversation\StrictMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StrictModeTest extends TestCase
{
    #[Test]
    public function allThreeCasesExist(): void
    {
        self::assertSame(StrictMode::Loud, StrictMode::from('loud'));
        self::assertSame(StrictMode::Lenient, StrictMode::from('lenient'));
        self::assertSame(StrictMode::Silent, StrictMode::from('silent'));
    }

    #[Test]
    public function backedValuesAreStable(): void
    {
        self::assertSame('loud', StrictMode::Loud->value);
        self::assertSame('lenient', StrictMode::Lenient->value);
        self::assertSame('silent', StrictMode::Silent->value);
    }

    #[Test]
    public function casesMethodReturnsAllThree(): void
    {
        self::assertCount(3, StrictMode::cases());
    }
}
