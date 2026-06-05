<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit;

use Phalanx\AiProviders\Effect\Decision;
use Phalanx\AiProviders\Effect\Decision\Verdict;
use Phalanx\AiProviders\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DecisionTest extends TestCase
{
    #[Test]
    public function grantedFactoryPopulatesCorrectFields(): void
    {
        $decision = Decision::granted('grant_leonidas_01');

        self::assertSame(Verdict::Granted, $decision->verdict);
        self::assertSame('grant_leonidas_01', $decision->grantId);
        self::assertSame([], $decision->reasonCodes);
        self::assertNull($decision->pauseReason);
    }

    #[Test]
    public function deniedFactoryPopulatesCorrectFields(): void
    {
        $decision = Decision::denied('hazard.ceiling.exceeded', 'scope.mismatch');

        self::assertSame(Verdict::Denied, $decision->verdict);
        self::assertNull($decision->grantId);
        self::assertContains('hazard.ceiling.exceeded', $decision->reasonCodes);
        self::assertContains('scope.mismatch', $decision->reasonCodes);
        self::assertNull($decision->pauseReason);
    }

    #[Test]
    public function pausedFactoryPopulatesCorrectFields(): void
    {
        $decision = Decision::paused('awaiting spartan council approval');

        self::assertSame(Verdict::Paused, $decision->verdict);
        self::assertNull($decision->grantId);
        self::assertSame([], $decision->reasonCodes);
        self::assertSame('awaiting spartan council approval', $decision->pauseReason);
    }

    #[Test]
    public function predicatesAreExclusive(): void
    {
        $granted = Decision::granted('g1');
        $denied = Decision::denied('no.grant');
        $paused = Decision::paused('pending olympus review');

        self::assertTrue($granted->isGranted());
        self::assertFalse($granted->isDenied());
        self::assertFalse($granted->isPaused());

        self::assertFalse($denied->isGranted());
        self::assertTrue($denied->isDenied());
        self::assertFalse($denied->isPaused());

        self::assertFalse($paused->isGranted());
        self::assertFalse($paused->isDenied());
        self::assertTrue($paused->isPaused());
    }

    #[Test]
    public function deniedDeduplicatesReasonCodes(): void
    {
        $decision = Decision::denied('hazard.exceeded', 'hazard.exceeded', 'scope.mismatch');

        self::assertCount(2, $decision->reasonCodes);
    }

    #[Test]
    public function toCanonicalSortsReasonCodes(): void
    {
        $a = Decision::denied('z.code', 'a.code', 'm.code');
        $b = Decision::denied('a.code', 'm.code', 'z.code');

        self::assertSame($a->toCanonical()['reason_codes'], $b->toCanonical()['reason_codes']);
    }

    #[Test]
    public function toCanonicalHasExpectedKeys(): void
    {
        $canonical = Decision::granted('grant_apollo_01')->toCanonical();

        self::assertArrayHasKey('verdict', $canonical);
        self::assertArrayHasKey('grant_id', $canonical);
        self::assertArrayHasKey('reason_codes', $canonical);
        self::assertArrayHasKey('pause_reason', $canonical);
        self::assertSame('granted', $canonical['verdict']);
    }

    #[Test]
    public function hashDeterminism(): void
    {
        $a = Decision::granted('grant_zeus_01');
        $b = Decision::granted('grant_zeus_01');

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function differentVerdictProducesDifferentHash(): void
    {
        $a = Decision::granted('g1');
        $b = Decision::paused('pending');

        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(Decision::granted('grant_zeus_01'));

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertSame(64, strlen($hash));
    }
}
