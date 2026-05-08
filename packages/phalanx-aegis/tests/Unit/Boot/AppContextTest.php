<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Boot;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\Exception\MissingContextValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppContextTest extends TestCase
{
    #[Test]
    public function fromSymfonyRuntimeReturnsStringValue(): void
    {
        $ctx = AppContext::fromSymfonyRuntime(['DB_URL' => 'pgsql://localhost/app']);

        self::assertSame('pgsql://localhost/app', $ctx->string('DB_URL'));
    }

    #[Test]
    public function intReturnsNativeIntegerDirectly(): void
    {
        $ctx = AppContext::test(['n' => 42]);

        self::assertSame(42, $ctx->int('n'));
    }

    #[Test]
    public function intAcceptsDefaultWhenKeyAbsent(): void
    {
        $ctx = AppContext::test([]);

        self::assertSame(0, $ctx->int('missing', 0));
    }

    #[Test]
    public function intCoercesNumericString(): void
    {
        $ctx = AppContext::test(['port' => '8080']);

        self::assertSame(8080, $ctx->int('port'));
    }

    #[Test]
    public function intCoercesNegativeNumericString(): void
    {
        $ctx = AppContext::test(['offset' => '-5']);

        self::assertSame(-5, $ctx->int('offset'));
    }

    #[Test]
    public function boolReturnsTrueForTrueSynonyms(): void
    {
        foreach (['1', 'true', 'on', 'yes', true] as $v) {
            $ctx = AppContext::test(['flag' => $v]);
            self::assertTrue($ctx->bool('flag'), sprintf('Expected true for value: %s', var_export($v, true)));
        }
    }

    #[Test]
    public function boolReturnsFalseForFalseSynonyms(): void
    {
        foreach (['0', 'false', 'off', 'no', '', false] as $v) {
            $ctx = AppContext::test(['flag' => $v]);
            self::assertFalse($ctx->bool('flag'), sprintf('Expected false for value: %s', var_export($v, true)));
        }
    }

    #[Test]
    public function requireThrowsWithKeyInMessageWhenAbsent(): void
    {
        $this->expectException(MissingContextValue::class);
        $this->expectExceptionMessage('missing');

        AppContext::test([])->require('missing');
    }

    #[Test]
    public function stringThrowsWhenKeyAbsentAndNoDefault(): void
    {
        $this->expectException(MissingContextValue::class);
        $this->expectExceptionMessage('missing');

        AppContext::test([])->string('missing');
    }

    #[Test]
    public function stringThrowsWrongTypeForArray(): void
    {
        $this->expectException(MissingContextValue::class);
        $this->expectExceptionMessage('array');

        AppContext::test(['x' => []])->string('x');
    }

    #[Test]
    public function withReturnsNewInstanceWithAddedKeyAndLeavesOriginalUnchanged(): void
    {
        $original = AppContext::test(['a' => 1]);
        $extended = $original->with('b', 2);

        self::assertSame(1, $extended->values['a']);
        self::assertSame(2, $extended->values['b']);
        self::assertFalse($original->has('b'));
        self::assertNotSame($original, $extended);
    }

    #[Test]
    public function withOverwritesExistingKeyInNewInstance(): void
    {
        $original = AppContext::test(['a' => 1]);
        $updated = $original->with('a', 99);

        self::assertSame(1, $original->values['a']);
        self::assertSame(99, $updated->values['a']);
    }

    #[Test]
    public function hasTrueForPresentKeyFalseForAbsent(): void
    {
        $ctx = AppContext::test(['present' => 'yes']);

        self::assertTrue($ctx->has('present'));
        self::assertFalse($ctx->has('absent'));
    }

    #[Test]
    public function emptyProducesContextWithNoValues(): void
    {
        $ctx = AppContext::empty();

        self::assertSame([], $ctx->values);
    }

    #[Test]
    public function testFactoryWithNoArgsProducesEmptyContext(): void
    {
        $ctx = AppContext::test();

        self::assertSame([], $ctx->values);
    }

    #[Test]
    public function getReturnsDefaultWhenKeyAbsent(): void
    {
        $ctx = AppContext::test([]);

        self::assertNull($ctx->get('nope'));
        self::assertSame('fallback', $ctx->get('nope', 'fallback'));
    }

    #[Test]
    public function boolThrowsWhenKeyAbsentAndNoDefault(): void
    {
        $this->expectException(MissingContextValue::class);
        $this->expectExceptionMessage('flag');

        AppContext::test([])->bool('flag');
    }

    #[Test]
    public function boolUsesDefaultWhenKeyAbsentAndDefaultProvided(): void
    {
        $ctx = AppContext::test([]);

        self::assertTrue($ctx->bool('flag', true));
        self::assertFalse($ctx->bool('flag', false));
    }

    #[Test]
    public function boolThrowsWrongTypeForNonBoolishValue(): void
    {
        $this->expectException(MissingContextValue::class);
        $this->expectExceptionMessage('bool');

        AppContext::test(['flag' => []])->bool('flag');
    }

    #[Test]
    public function intThrowsWhenKeyAbsentAndNoDefault(): void
    {
        $this->expectException(MissingContextValue::class);
        $this->expectExceptionMessage('port');

        AppContext::test([])->int('port');
    }

    #[Test]
    public function intThrowsWrongTypeForNonNumericString(): void
    {
        $this->expectException(MissingContextValue::class);
        $this->expectExceptionMessage('int');

        AppContext::test(['port' => 'abc'])->int('port');
    }
}
