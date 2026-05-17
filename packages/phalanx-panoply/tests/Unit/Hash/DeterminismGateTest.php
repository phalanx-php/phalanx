<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Hash;

use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Decision;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance gate for Canonical hash determinism. Every test here is a
 * property that must hold across PHP versions, key orderings, timezones,
 * and host platforms.
 *
 * The `canonicalAlgorithmAnchor` test pins the exact byte output for a
 * deterministic fixture. Any normalization change — key sort order,
 * float encoding, datetime format — breaks this test with a clear
 * "algorithm changed" signal. That is intentional: silent drift in the
 * canonical encoding would silently invalidate cache keys, replay markers,
 * and audit fingerprints already stored by callers.
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
    public function floatAndIntegerHashDistinctly(): void
    {
        // JSON_PRESERVE_ZERO_FRACTION emits 1.0 as `1.0` — it must NOT be
        // conflated with integer 1. This keeps float/int distinct for
        // external JCS verifiers.
        $intHash   = Canonical::of(1);
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
        $inv1 = self::deterministicInvocation();
        $inv2 = self::deterministicInvocation();

        self::assertSame(
            Canonical::of($inv1),
            Canonical::of($inv2),
            'two Invocations built from identical data must hash identically',
        );
    }

    #[Test]
    public function timezoneDoesNotAffectInvocationHash(): void
    {
        $utc    = new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00');
        $athens = new \DateTimeImmutable('2026-05-17T15:00:00.123456+03:00');

        $invUtc    = self::buildInvocation($utc);
        $invAthens = self::buildInvocation($athens);

        self::assertSame(
            Canonical::of($invUtc),
            Canonical::of($invAthens),
            'same instant in different timezones must produce the same hash',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchor(): void
    {
        // Pinned fixture: deterministic Invocation with frozen field values
        // and microsecond-precise UTC timestamp.
        //
        // This anchor was computed on 2026-05-17 against the canonical
        // normalization algorithm (SHA-256 over JCS-sorted JSON, UTC ISO 8601
        // with microseconds). Any future change to Canonical::normalize() or
        // Canonicalizable::toCanonical() that alters byte output surfaces here
        // with a clear "algorithm changed" failure — not a silent invalidation.
        $inv  = self::deterministicInvocation();
        $hash = Canonical::of($inv);

        self::assertSame(
            '107ed690f521566cc9968fd911f565ab2378c886898b3c8831df8792944ef021',
            $hash,
            'Canonical algorithm output drifted — verify normalization changes are intentional',
        );
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Canonical::of(self::deterministicInvocation()));
    }

    #[Test]
    public function canonicalAlgorithmAnchorForMessage(): void
    {
        $message = new Message(
            id: '01HZ000000000000000000RECORD',
            sequence: 1,
            at: new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00'),
            role: 'assistant',
            text: 'Leonidas at Thermopylae held the pass for three days.',
            attachments: [],
        );

        $hash = Canonical::of($message);

        // Anchor — pasted from first green run. Future Canonical algorithm
        // drift surfaces here with a clear "algorithm changed" signal.
        self::assertSame(
            '9638dbb127588a4197545c9aeddf089141652cd52e7560bda3803840d11b2bfb',
            $hash,
            'Canonical algorithm output drifted for Record\Message — verify normalization changes are intentional',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForTokenDelta(): void
    {
        // Highest-volume cue — a streaming token delta with fixed fields.
        $cue = new TokenDelta(
            id: '01HZ000000000000000000TD0001',
            sequence: 1,
            activityId: 'act.thermopylae',
            invocationId: '01HZ000000000000000000INV001',
            agentId: 'agent.leonidas',
            at: new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00'),
            text: 'Hold the pass.',
            channel: Channel::Message,
        );

        self::assertSame(
            'f25b581e5b5df47f4bf8dd5108a2ba2d333057b2bc47996c0982e0e8709c9d99',
            Canonical::of($cue),
            'Canonical algorithm output drifted for Cue\Output\TokenDelta',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForEffect(): void
    {
        // Effect — Authorizer/Scorer input type.
        $effect = Effect::of(
            id: '01HZ000000000000000000EFF001',
            kind: EffectKind::FileRead,
            summary: 'Read hoplite formation data from /var/phalanx/sparta.json',
            arguments: ['path' => '/var/phalanx/sparta.json'],
            requiresApproval: false,
            hazard: Hazard::Low,
        );

        self::assertSame(
            'f7d31a17efbe946be90572a676b3541f5db9e4d00302a4b36cd8f533e217447c',
            Canonical::of($effect),
            'Canonical algorithm output drifted for Effect',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForGrant(): void
    {
        // Grant — authorization payload issued to agents.
        $grant = Grant::of(
            id: '01HZ000000000000000000GRT001',
            subject: 'agent.leonidas',
            allowedEffects: [EffectKind::FileRead, EffectKind::CodeSearch],
            scope: 'thermopylae.battle',
            hazardCeiling: Hazard::Medium,
            expiresAt: new \DateTimeImmutable('2026-05-17T18:00:00.000000+00:00'),
            conditions: ['battlefield' => 'thermopylae', 'year' => -480],
        );

        self::assertSame(
            '2e12b982169d8117f4d3cff895fd4764e6b7de1dcc6f1f43e1682dc9b1f4a01c',
            Canonical::of($grant),
            'Canonical algorithm output drifted for Grant',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForDecision(): void
    {
        // Effect\Decision — Authorizer output type.
        $decision = Decision::granted('01HZ000000000000000000GRT001');

        self::assertSame(
            '62cc2d4b10dc6029de18dbb25641e551d5fb76f801ac779fe74b15655e87ebd3',
            Canonical::of($decision),
            'Canonical algorithm output drifted for Effect\Decision',
        );
    }

    /**
     * Deterministic Invocation fixture with fully frozen field values.
     * Every field is a literal constant — no `new \DateTimeImmutable()` calls
     * without an explicit timestamp, no runtime-generated IDs.
     *
     * The fixture's `output`, `effects`, `provider`, and `transport` properties
     * are themselves {@see Canonicalizable}, so the algorithm anchor implicitly
     * covers nested-canonical recursion through the entire object graph.
     */
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
}
