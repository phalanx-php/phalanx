<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Invocation::class)]
final class InvocationTest extends TestCase
{
    public function test_of_constructs_with_timestamp(): void
    {
        $inv = self::fixture();

        self::assertSame('inv_1', $inv->id);
        self::assertSame('investigator', $inv->agentId);
        self::assertSame('act_1', $inv->activityId);
    }

    public function test_prompt_hash_is_64_char_hex(): void
    {
        $inv = self::fixture();
        $hash = $inv->promptHash();

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_same_invocation_hashes_identically(): void
    {
        $created = new \DateTimeImmutable('2026-05-17T12:00:00Z');

        $a = self::fixture(createdAt: $created);
        $b = self::fixture(createdAt: $created);

        self::assertSame($a->promptHash(), $b->promptHash());
    }

    public function test_different_instructions_produce_different_hashes(): void
    {
        $created = new \DateTimeImmutable('2026-05-17T12:00:00Z');

        $a = self::fixture(instructions: 'summarize', createdAt: $created);
        $b = self::fixture(instructions: 'critique',  createdAt: $created);

        self::assertNotSame($a->promptHash(), $b->promptHash());
    }

    public function test_dynamic_context_key_order_does_not_affect_hash(): void
    {
        $created = new \DateTimeImmutable('2026-05-17T12:00:00Z');

        $a = self::fixture(dynamicContext: ['x' => 1, 'y' => 2], createdAt: $created);
        $b = self::fixture(dynamicContext: ['y' => 2, 'x' => 1], createdAt: $created);

        self::assertSame($a->promptHash(), $b->promptHash());
    }

    public function test_canonical_form_is_json_serializable(): void
    {
        $inv = self::fixture();
        $json = json_encode(Canonical::normalize($inv));

        self::assertIsString($json);
        self::assertJson($json);
    }

    /**
     * @param array<string, mixed> $dynamicContext
     */
    private static function fixture(
        string $instructions = 'Investigate.',
        array $dynamicContext = [],
        ?\DateTimeImmutable $createdAt = null,
    ): Invocation {
        return Invocation::of(
            id: 'inv_1',
            agentId: 'investigator',
            activityId: 'act_1',
            contextHash: 'deadbeef' . str_repeat('0', 56),
            instructions: $instructions,
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
            dynamicContext: $dynamicContext,
            createdAt: $createdAt ?? new \DateTimeImmutable('2026-05-17T12:00:00Z'),
        );
    }
}
