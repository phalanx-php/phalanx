<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Hash;

use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Hash\Canonical;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance gate for Canonical hash determinism. The exact anchor pins one
 * representative object graph; the other tests cover invariants callers rely on
 * without duplicating that anchor across every DTO and enum shape in AiProviders.
 */
final class DeterminismGateTest extends TestCase
{
    #[Test]
    public function arrayKeyOrderIsIrrelevant(): void
    {
        $ab = Canonical::of(['a' => 1, 'b' => 2]);
        $ba = Canonical::of(['b' => 2, 'a' => 1]);

        self::assertSame($ab, $ba, 'Canonical must sort assoc keys; insertion order must not affect the hash');
    }

    #[Test]
    public function nestedMapKeyOrderIsIrrelevant(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00');
        $zebra = self::buildInvocationWithContext(['zebra' => 1, 'apple' => 2], $at);
        $apple = self::buildInvocationWithContext(['apple' => 2, 'zebra' => 1], $at);

        self::assertSame(
            Canonical::of($zebra),
            Canonical::of($apple),
            'dynamicContext key insertion order must not affect the canonical hash',
        );
    }

    #[Test]
    public function floatAndIntegerHashDistinctly(): void
    {
        $intHash = Canonical::of(1);
        $floatHash = Canonical::of(1.0);

        self::assertNotSame(
            $intHash,
            $floatHash,
            'int 1 and float 1.0 must hash distinctly (JSON_PRESERVE_ZERO_FRACTION)',
        );
    }

    #[Test]
    public function reconstructedInvocationHashesIdentically(): void
    {
        self::assertSame(
            Canonical::of(self::deterministicInvocation()),
            Canonical::of(self::deterministicInvocation()),
            'two Invocations built from identical data must hash identically',
        );
    }

    #[Test]
    public function timezoneDoesNotAffectInvocationHash(): void
    {
        $utc = new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00');
        $athens = new \DateTimeImmutable('2026-05-17T15:00:00.123456+03:00');

        self::assertSame(
            Canonical::of(self::buildInvocation($utc)),
            Canonical::of(self::buildInvocation($athens)),
            'same instant in different timezones must produce the same hash',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchor(): void
    {
        self::assertSame(
            '107ed690f521566cc9968fd911f565ab2378c886898b3c8831df8792944ef021',
            Canonical::of(self::deterministicInvocation()),
            'Canonical algorithm output drifted; verify normalization changes are intentional',
        );
    }

    private static function deterministicInvocation(): Invocation
    {
        return self::buildInvocation(new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00'));
    }

    private static function buildInvocation(\DateTimeImmutable $createdAt): Invocation
    {
        return new Invocation(
            id: '01HZ000000000000000000ANCHOR',
            agentId: 'agent.leonidas',
            activityId: 'act.thermopylae',
            contextHash: str_repeat('0', 64),
            instructions: 'Hold the pass at Thermopylae until the last hoplite falls.',
            output: Output::text(),
            effects: Effects::none(),
            provider: ProviderNeeds::new(),
            transport: TransportNeeds::new(),
            dynamicContext: ['battle' => 'thermopylae', 'year' => -480],
            createdAt: $createdAt,
        );
    }

    /**
     * @param array<string, mixed> $dynamicContext
     */
    private static function buildInvocationWithContext(array $dynamicContext, \DateTimeImmutable $createdAt): Invocation
    {
        return new Invocation(
            id: '01HZ000000000000000000KMORD',
            agentId: 'agent.leonidas',
            activityId: 'act.thermopylae',
            contextHash: str_repeat('0', 64),
            instructions: 'Hold the pass at Thermopylae until the last hoplite falls.',
            output: Output::text(),
            effects: Effects::none(),
            provider: ProviderNeeds::new(),
            transport: TransportNeeds::new(),
            dynamicContext: $dynamicContext,
            createdAt: $createdAt,
        );
    }
}
