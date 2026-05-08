<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Boot;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootEvaluation;
use Phalanx\Boot\Required;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RequiredTest extends PhalanxTestCase
{
    #[Test]
    public function envPassesWhenKeyPresentAndNonEmpty(): void
    {
        $ctx = AppContext::test(['DB_URL' => 'pgsql://localhost/app']);
        $ev = Required::env('DB_URL')->evaluate($ctx);

        self::assertTrue($ev->isPass());
    }

    #[Test]
    public function envFailsWhenKeyAbsent(): void
    {
        $ctx = AppContext::test([]);
        $ev = Required::env('DB_URL')->evaluate($ctx);

        self::assertTrue($ev->isFail());
        self::assertNotNull($ev->remediation);
        self::assertStringContainsString('DB_URL', $ev->remediation);
    }

    #[Test]
    public function envFailsWhenValueIsEmptyString(): void
    {
        $ctx = AppContext::test(['DB_URL' => '']);
        $ev = Required::env('DB_URL')->evaluate($ctx);

        self::assertTrue($ev->isFail());
    }

    #[Test]
    public function envFailsWhenValueIsNull(): void
    {
        $ctx = AppContext::test(['DB_URL' => null]);
        $ev = Required::env('DB_URL')->evaluate($ctx);

        self::assertTrue($ev->isFail());
    }

    #[Test]
    public function envUsesCustomDescription(): void
    {
        $req = Required::env('DB_URL', 'Primary database connection');

        self::assertSame('Primary database connection', $req->description);
    }

    #[Test]
    public function envKindIsCorrect(): void
    {
        $req = Required::env('DB_URL');

        self::assertSame(Required::KIND_ENV, $req->kind);
    }

    #[Test]
    public function serviceAlwaysPassesRegardlessOfContext(): void
    {
        $ctx = AppContext::test([]);
        $ev = Required::service('App\\Repository')->evaluate($ctx);

        self::assertTrue($ev->isPass());
    }

    #[Test]
    public function serviceKindIsCorrect(): void
    {
        $req = Required::service('App\\Repository');

        self::assertSame(Required::KIND_SERVICE, $req->kind);
    }

    #[Test]
    public function callablePassesWhenFnReturnsTrue(): void
    {
        $ctx = AppContext::test([]);
        $ev = Required::callable(static fn (AppContext $c): bool => true, 'custom check')->evaluate($ctx);

        self::assertTrue($ev->isPass());
    }

    #[Test]
    public function callableFailsWhenFnReturnsFalse(): void
    {
        $ctx = AppContext::test([]);
        $ev = Required::callable(static fn (AppContext $c): bool => false, 'custom check')->evaluate($ctx);

        self::assertTrue($ev->isFail());
    }

    #[Test]
    public function callableFailsWithStringReasonAsRemediation(): void
    {
        $ctx = AppContext::test([]);
        $ev = Required::callable(
            static fn (AppContext $c): string => 'run: composer install',
            'dependency check',
        )->evaluate($ctx);

        self::assertTrue($ev->isFail());
        self::assertSame('run: composer install', $ev->remediation);
    }

    #[Test]
    public function callablePassThroughsBootEvaluationUnchanged(): void
    {
        $ctx = AppContext::test([]);
        $expected = BootEvaluation::warn('already a warning');
        $ev = Required::callable(
            static fn (AppContext $c): BootEvaluation => BootEvaluation::warn('already a warning'),
            'passthrough check',
        )->evaluate($ctx);

        self::assertTrue($ev->isWarn());
        self::assertSame($expected->message, $ev->message);
    }

    #[Test]
    public function callableKindIsCorrect(): void
    {
        $req = Required::callable(static fn (AppContext $c): bool => true, 'desc');

        self::assertSame(Required::KIND_CALLABLE, $req->kind);
    }
}
