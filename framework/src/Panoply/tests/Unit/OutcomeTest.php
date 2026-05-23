<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Effect\Outcome;
use Phalanx\Panoply\Effect\Outcome\State;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutcomeTest extends TestCase
{
    #[Test]
    public function succeededFactoryPopulatesCorrectFields(): void
    {
        $outcome = Outcome::succeeded('sha256:abc123', 42);

        self::assertSame(State::Succeeded, $outcome->state);
        self::assertSame('sha256:abc123', $outcome->valueDigest);
        self::assertNull($outcome->errorClass);
        self::assertNull($outcome->errorMessage);
        self::assertSame(42, $outcome->durationMs);
    }

    #[Test]
    public function failedFactoryPopulatesCorrectFields(): void
    {
        $outcome = Outcome::failed(\RuntimeException::class, 'hoplite count mismatch', 17);

        self::assertSame(State::Failed, $outcome->state);
        self::assertNull($outcome->valueDigest);
        self::assertSame(\RuntimeException::class, $outcome->errorClass);
        self::assertSame('hoplite count mismatch', $outcome->errorMessage);
        self::assertSame(17, $outcome->durationMs);
    }

    #[Test]
    public function cancelledFactoryPopulatesCorrectFields(): void
    {
        $outcome = Outcome::cancelled(5);

        self::assertSame(State::Cancelled, $outcome->state);
        self::assertNull($outcome->valueDigest);
        self::assertNull($outcome->errorClass);
        self::assertNull($outcome->errorMessage);
        self::assertSame(5, $outcome->durationMs);
    }

    #[Test]
    public function predicatesAreExclusive(): void
    {
        $succeeded = Outcome::succeeded(null, 10);
        $failed = Outcome::failed(\RuntimeException::class, 'agora market inventory mismatch', 10);
        $cancelled = Outcome::cancelled(10);

        self::assertTrue($succeeded->isSucceeded());
        self::assertFalse($succeeded->isFailed());
        self::assertFalse($succeeded->isCancelled());

        self::assertFalse($failed->isSucceeded());
        self::assertTrue($failed->isFailed());
        self::assertFalse($failed->isCancelled());

        self::assertFalse($cancelled->isSucceeded());
        self::assertFalse($cancelled->isFailed());
        self::assertTrue($cancelled->isCancelled());
    }

    #[Test]
    public function toCanonicalHasExpectedKeys(): void
    {
        $canonical = Outcome::succeeded('abc', 100)->toCanonical();

        self::assertArrayHasKey('state', $canonical);
        self::assertArrayHasKey('value_digest', $canonical);
        self::assertArrayHasKey('error_class', $canonical);
        self::assertArrayHasKey('error_message', $canonical);
        self::assertArrayHasKey('duration_ms', $canonical);
        self::assertSame('succeeded', $canonical['state']);
    }

    #[Test]
    public function hashDeterminism(): void
    {
        $a = Outcome::succeeded('olympus_digest', 99);
        $b = Outcome::succeeded('olympus_digest', 99);

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function differentDurationProducesDifferentHash(): void
    {
        $a = Outcome::cancelled(10);
        $b = Outcome::cancelled(20);

        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(Outcome::succeeded('olympus_digest', 99));

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertSame(64, strlen($hash));
    }

    #[Test]
    public function cancelledLeavesValueAndErrorFieldsNull(): void
    {
        $outcome = Outcome::cancelled(42);

        self::assertNull($outcome->valueDigest);
        self::assertNull($outcome->errorClass);
        self::assertNull($outcome->errorMessage);
        self::assertSame(42, $outcome->durationMs);
    }

    #[Test]
    public function succeededLeavesErrorFieldsNull(): void
    {
        $outcome = Outcome::succeeded('digest_abc', 15);

        self::assertNull($outcome->errorClass);
        self::assertNull($outcome->errorMessage);
        self::assertSame('digest_abc', $outcome->valueDigest);
    }

    #[Test]
    public function failedLeavesValueDigestNull(): void
    {
        $outcome = Outcome::failed(\RuntimeException::class, 'thermopylae breach', 7);

        self::assertNull($outcome->valueDigest);
        self::assertSame(\RuntimeException::class, $outcome->errorClass);
        self::assertSame('thermopylae breach', $outcome->errorMessage);
    }
}
