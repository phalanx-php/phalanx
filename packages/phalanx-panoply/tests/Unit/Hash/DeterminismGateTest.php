<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Hash;

use Phalanx\Panoply\Artifact;
use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Options;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Cue\Activity\Started as CueActivityStarted;
use Phalanx\Panoply\Cue\Artifact\Finalized as CueArtifactFinalized;
use Phalanx\Panoply\Cue\Effect\Authorized as CueEffectAuthorized;
use Phalanx\Panoply\Cue\Invocation\Completed as CueInvocationCompleted;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Decision;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effect\Outcome;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Config;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Phalanx\Panoply\Transport\Request;
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
        $utc = new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00');
        $athens = new \DateTimeImmutable('2026-05-17T15:00:00.123456+03:00');

        $invUtc = self::buildInvocation($utc);
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
        $inv = self::deterministicInvocation();
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

    #[Test]
    public function canonicalAlgorithmAnchorForDecisionDenied(): void
    {
        // Denied variant — complements the existing granted-verdict anchor.
        $decision = Decision::denied('effect-not-allowed', 'hazard-exceeds-ceiling');

        self::assertSame(
            'afeb2bbca7a081ce418c84101c36f3fd81bf7a943472da807f45d0b5763b15f8',
            Canonical::of($decision),
            'Canonical algorithm output drifted for Effect\Decision (denied variant)',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForOutcome(): void
    {
        $outcome = Outcome::succeeded('a1b2c3d4', 42);

        self::assertSame(
            '3bb8acb98b3750f4d51792c5490d8a2e3e6b2050893606cfc76c12a49fdbea6c',
            Canonical::of($outcome),
            'Canonical algorithm output drifted for Effect\Outcome',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForCapabilities(): void
    {
        $caps = Capabilities::of(Capability::ToolUse, Capability::Vision);

        self::assertSame(
            '9a7e10d7645535515dd0423b4d3130316e31b9a9a53d666e22641477f8d9e06f',
            Canonical::of($caps),
            'Canonical algorithm output drifted for Capabilities',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForContext(): void
    {
        $ctx = Context::new()
            ->front('FrontSource')
            ->middle('MidSource')
            ->tail('TailSource');

        self::assertSame(
            'dc55c6103302e9f238d24f311c48b6cb4beae64ca3fd410da69600b75a5cd966',
            Canonical::of($ctx),
            'Canonical algorithm output drifted for Context',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForOutput(): void
    {
        $output = Output::artifact(ArtifactKind::Thesis);

        self::assertSame(
            '37e2179029202dfd8aa7dc6e4c2a4f369d4ce831a5823d60328e7e02d3f496f3',
            Canonical::of($output),
            'Canonical algorithm output drifted for Output',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForEffects(): void
    {
        $effects = Effects::allow(EffectKind::FileRead, EffectKind::ShellExec)
            ->requireApproval(EffectKind::ShellExec);

        self::assertSame(
            '8b0a200d9caa1b64e17361a9525b87a0e73b08cdb4e1e0a70a8342dd6f60971f',
            Canonical::of($effects),
            'Canonical algorithm output drifted for Effects',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForConversationOptions(): void
    {
        $options = Options::lenient();

        self::assertSame(
            'd4e2bbe043893c878e01aa6843c49b7c4dbd5a847095e319044dc9232554efca',
            Canonical::of($options),
            'Canonical algorithm output drifted for Conversation\Options',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForTransportRequest(): void
    {
        $request = Request::of(
            'POST',
            'https://example.com/v1/messages',
            ['Content-Type' => 'application/json'],
            '{"model":"haiku"}',
        );

        self::assertSame(
            '93205688df8d6f5e56dabe567fc2068b370576a2da69970c60a8db475b183510',
            Canonical::of($request),
            'Canonical algorithm output drifted for Transport\Request',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForTransportNeeds(): void
    {
        $needs = TransportNeeds::new()->streaming()->cancellable();

        self::assertSame(
            '93fb66ef428f8fe98b535da391051a2dbc234e5f80b3d39138876e78159355bf',
            Canonical::of($needs),
            'Canonical algorithm output drifted for Transport\Needs',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForProviderConfigModel(): void
    {
        $model = Model::of(
            name: 'apollo',
            modelId: 'apollo-20260101',
            aliases: ['apollo', 'light'],
            capabilities: Capabilities::of(Capability::Reasoning),
            inputPricing: 0.001,
            outputPricing: 0.005,
        );

        self::assertSame(
            'fe285b307827749bf7a15af8120c666b353a16dd5681356a0ae52ab554b66e5b',
            Canonical::of($model),
            'Canonical algorithm output drifted for Provider\Config\Model',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForProviderConfig(): void
    {
        $model = Model::of(
            name: 'apollo',
            modelId: 'apollo-20260101',
            aliases: ['apollo', 'light'],
            capabilities: Capabilities::of(Capability::Reasoning),
            inputPricing: 0.001,
            outputPricing: 0.005,
        );

        $config = Config::of(
            id: 'olympus',
            displayName: 'Olympus Provider',
            models: [$model],
            capabilities: Capabilities::of(Capability::Reasoning, Capability::ToolUse),
            transport: TransportNeeds::new()->streaming(),
            wireTranslator: null,
        );

        self::assertSame(
            '6725a6706765a099c83139068e9306a0fb307fce23c57a397f7a3860a28a5488',
            Canonical::of($config),
            'Canonical algorithm output drifted for Provider\Config',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForProviderNeeds(): void
    {
        $needs = ProviderNeeds::new()
            ->prefer(Preference::LocalFirst)
            ->require(Capability::Reasoning);

        self::assertSame(
            '64b295cbd51ed90028f364ccb9cb9f72978e8d598ba7261472e2f9f6219efae5',
            Canonical::of($needs),
            'Canonical algorithm output drifted for Provider\Needs',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForArtifact(): void
    {
        $artifact = Artifact::draft(
            id: '01HZ000000000000000000ART001',
            kind: ArtifactKind::Thesis,
            agentId: 'agent.leonidas',
            activityId: 'act.thermopylae',
            title: 'Battle Report',
            createdAt: new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00'),
        );

        self::assertSame(
            '7447abff9311337dd7f09dc05936da67eb74466b11a3d4ff521cdfb0d58da41e',
            Canonical::of($artifact),
            'Canonical algorithm output drifted for Artifact',
        );
    }

    // ── Cue subclass anchors (A4) ─────────────────────────────────────────────

    #[Test]
    public function canonicalAlgorithmAnchorForCueEffectAuthorized(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00');
        $cue = new CueEffectAuthorized(
            '01HZ000000000000000000AUTH01',
            1,
            'act.thermopylae',
            '01HZ000000000000000000INV001',
            'agent.leonidas',
            $at,
            effectId: 'eff.sparta.01',
            grantId: 'grt.zeus.01',
        );

        self::assertSame(
            'afad2b95371d34b33e488bdc3124610fc8cd80b4cf8cc9c28f801ed93c571ff4',
            Canonical::of($cue),
            'Canonical algorithm output drifted for Cue\Effect\Authorized',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForCueInvocationCompleted(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00');
        $cue = new CueInvocationCompleted(
            '01HZ000000000000000000COMP01',
            2,
            'act.thermopylae',
            '01HZ000000000000000000INV001',
            'agent.leonidas',
            $at,
            StopReason::EndOfTurn,
        );

        self::assertSame(
            'ae0333b3407e6e2bc68bb25c782238f4286e558ad78980ffa0ba30357c3da56a',
            Canonical::of($cue),
            'Canonical algorithm output drifted for Cue\Invocation\Completed',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForCueActivityStarted(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00');
        $cue = new CueActivityStarted(
            '01HZ000000000000000000STRT01',
            3,
            'act.thermopylae',
            '01HZ000000000000000000INV001',
            'agent.leonidas',
            $at,
        );

        self::assertSame(
            'b61d3d551a8915aa9978e33249eb6bd0db303cfa31394d572b414af915387f57',
            Canonical::of($cue),
            'Canonical algorithm output drifted for Cue\Activity\Started',
        );
    }

    #[Test]
    public function canonicalAlgorithmAnchorForCueArtifactFinalized(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00');
        $cue = new CueArtifactFinalized(
            '01HZ000000000000000000FINL01',
            4,
            'act.thermopylae',
            '01HZ000000000000000000INV001',
            'agent.leonidas',
            $at,
            artifactId: '01HZ000000000000000000ART001',
            contentHash: str_repeat('a', 64),
        );

        self::assertSame(
            'd2a076d0e53a2ed77dab83f956ebaabe13c80fa4745870b9524340811d4e777d',
            Canonical::of($cue),
            'Canonical algorithm output drifted for Cue\Artifact\Finalized',
        );
    }

    // ── Nested map key order (A5) ─────────────────────────────────────────────

    #[Test]
    public function nestedMapKeyOrderIsIrrelevant(): void
    {
        // Dynamic context keys in non-alphabetical order — canonicalization must
        // sort through the full object graph, not just top-level arrays.
        $at = new \DateTimeImmutable('2026-05-17T12:00:00.123456+00:00');
        $zebra = self::buildInvocationWithContext(['zebra' => 1, 'apple' => 2], $at);
        $apple = self::buildInvocationWithContext(['apple' => 2, 'zebra' => 1], $at);
        $zebraHash = Canonical::of($zebra);
        $appleHash = Canonical::of($apple);

        self::assertSame(
            $zebraHash,
            $appleHash,
            'dynamicContext key insertion order must not affect the canonical hash',
        );
        self::assertSame(
            '06cce447b9a63e610fa974de07353c38d8461d75f8622ed075efa09375eab11f',
            $zebraHash,
            'Canonical algorithm output drifted for nested-map key order anchor',
        );
    }

    // ── Set-semantics order-invariance (Q6 + Q7) ─────────────────────────────

    #[Test]
    public function capabilitiesOrderInvariant(): void
    {
        $toolVision = Capabilities::of(Capability::ToolUse, Capability::Vision);
        $visionTool = Capabilities::of(Capability::Vision, Capability::ToolUse);

        self::assertSame(
            Canonical::of($toolVision),
            Canonical::of($visionTool),
            'Capabilities::of() insertion order must not affect the canonical hash',
        );
    }

    #[Test]
    public function effectsAllowListOrderInvariant(): void
    {
        $fileShell = Effects::allow(EffectKind::FileRead, EffectKind::ShellExec);
        $shellFile = Effects::allow(EffectKind::ShellExec, EffectKind::FileRead);

        self::assertSame(
            Canonical::of($fileShell),
            Canonical::of($shellFile),
            'Effects::allow() insertion order must not affect the canonical hash',
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
