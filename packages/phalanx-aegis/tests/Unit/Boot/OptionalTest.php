<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Boot;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootEvaluation;
use Phalanx\Boot\Optional;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class OptionalTest extends PhalanxTestCase
{
    #[Test]
    public function envPassesWhenKeyPresentAndNonEmpty(): void
    {
        $ctx = new AppContext(['CACHE_URL' => 'redis://localhost']);
        $ev = Optional::env('CACHE_URL')->evaluate($ctx);

        self::assertTrue($ev->isPass());
    }

    #[Test]
    public function envWarnsWhenKeyAbsent(): void
    {
        $ctx = new AppContext([]);
        $ev = Optional::env('CACHE_URL')->evaluate($ctx);

        self::assertTrue($ev->isWarn());
        self::assertFalse($ev->isFail());
    }

    #[Test]
    public function envWarnsWhenValueIsEmptyString(): void
    {
        $ctx = new AppContext(['CACHE_URL' => '']);
        $ev = Optional::env('CACHE_URL')->evaluate($ctx);

        self::assertTrue($ev->isWarn());
    }

    #[Test]
    public function envWarnsWhenValueIsNull(): void
    {
        $ctx = new AppContext(['CACHE_URL' => null]);
        $ev = Optional::env('CACHE_URL')->evaluate($ctx);

        self::assertTrue($ev->isWarn());
    }

    #[Test]
    public function envWarnMessageIncludesFallbackWhenProvided(): void
    {
        $ctx = new AppContext([]);
        $ev = Optional::env('CACHE_URL', 'array://')->evaluate($ctx);

        self::assertStringContainsString('array://', $ev->message);
    }

    #[Test]
    public function envWarnMessageIncludesNoneWhenNoFallback(): void
    {
        $ctx = new AppContext([]);
        $ev = Optional::env('CACHE_URL')->evaluate($ctx);

        self::assertStringContainsString('<none>', $ev->message);
    }

    #[Test]
    public function envKindIsCorrect(): void
    {
        $opt = Optional::env('CACHE_URL');

        self::assertSame(Optional::KIND_ENV, $opt->kind);
    }

    #[Test]
    public function serviceAlwaysPassesRegardlessOfContext(): void
    {
        $ctx = new AppContext([]);
        $ev = Optional::service('App\\Cache')->evaluate($ctx);

        self::assertTrue($ev->isPass());
    }

    #[Test]
    public function serviceKindIsCorrect(): void
    {
        $opt = Optional::service('App\\Cache');

        self::assertSame(Optional::KIND_SERVICE, $opt->kind);
    }

    #[Test]
    public function callablePassesWhenFnReturnsTrue(): void
    {
        $ctx = new AppContext([]);
        $ev = Optional::callable(static fn (AppContext $c): bool => true, 'optional check')->evaluate($ctx);

        self::assertTrue($ev->isPass());
    }

    #[Test]
    public function callableWarnsWhenFnReturnsFalse(): void
    {
        $ctx = new AppContext([]);
        $ev = Optional::callable(static fn (AppContext $c): bool => false, 'optional check')->evaluate($ctx);

        self::assertTrue($ev->isWarn());
        self::assertFalse($ev->isFail());
    }

    #[Test]
    public function callableWarnsWithStringReasonAsRemediation(): void
    {
        $ctx = new AppContext([]);
        $ev = Optional::callable(
            static fn (AppContext $c): string => 'install optional extension',
            'ext check',
        )->evaluate($ctx);

        self::assertTrue($ev->isWarn());
        self::assertSame('install optional extension', $ev->remediation);
    }

    #[Test]
    public function callablePassThroughsBootEvaluationUnchanged(): void
    {
        $ctx = new AppContext([]);
        $ev = Optional::callable(
            static fn (AppContext $c): BootEvaluation => BootEvaluation::fail('hard fail from optional'),
            'passthrough check',
        )->evaluate($ctx);

        self::assertTrue($ev->isFail());
        self::assertSame('hard fail from optional', $ev->message);
    }

    #[Test]
    public function callableKindIsCorrect(): void
    {
        $opt = Optional::callable(static fn (AppContext $c): bool => true, 'desc');

        self::assertSame(Optional::KIND_CALLABLE, $opt->kind);
    }
}
