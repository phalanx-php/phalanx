<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit;

use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    #[Test]
    public function ofCreatesSetFromCases(): void
    {
        $caps = Capabilities::of(Capability::Reasoning, Capability::ToolUse);

        self::assertTrue($caps->has(Capability::Reasoning));
        self::assertTrue($caps->has(Capability::ToolUse));
        self::assertFalse($caps->has(Capability::Vision));
    }

    #[Test]
    public function withReturnsNewInstance(): void
    {
        $original = Capabilities::of(Capability::Reasoning);
        $extended = $original->with(Capability::ToolUse);

        self::assertNotSame($original, $extended);
        self::assertFalse($original->has(Capability::ToolUse));
        self::assertTrue($extended->has(Capability::ToolUse));
    }

    #[Test]
    public function duplicatesAreDeduped(): void
    {
        $caps = Capabilities::of(Capability::Reasoning, Capability::Reasoning, Capability::ToolUse);

        self::assertCount(2, $caps->cases);
    }

    #[Test]
    public function satisfiesChecksAllRequired(): void
    {
        $caps = Capabilities::of(Capability::Reasoning, Capability::ToolUse, Capability::Vision);

        self::assertTrue($caps->satisfies(Capability::Reasoning, Capability::ToolUse));
        self::assertFalse($caps->satisfies(Capability::Reasoning, Capability::JsonMode));
    }

    #[Test]
    public function intersectPreservesOnlyShared(): void
    {
        $a = Capabilities::of(Capability::Reasoning, Capability::ToolUse, Capability::Vision);
        $b = Capabilities::of(Capability::Vision, Capability::JsonMode);

        $shared = $a->intersect($b);

        self::assertTrue($shared->has(Capability::Vision));
        self::assertFalse($shared->has(Capability::Reasoning));
        self::assertFalse($shared->has(Capability::JsonMode));
    }

    #[Test]
    public function customTagsSurviveWithAndWithout(): void
    {
        $caps = Capabilities::of(Capability::Reasoning)
            ->withCustom('vendor-feature-a', 'vendor-feature-b');

        self::assertTrue($caps->hasCustom('vendor-feature-a'));
        self::assertTrue($caps->hasCustom('vendor-feature-b'));

        $reduced = $caps->without(Capability::Reasoning);

        self::assertFalse($reduced->has(Capability::Reasoning));
        self::assertTrue($reduced->hasCustom('vendor-feature-a'));
    }

    #[Test]
    public function toCanonicalSortsCasesAndCustom(): void
    {
        $caps = Capabilities::of(Capability::Vision, Capability::Reasoning, Capability::ToolUse)
            ->withCustom('zebra', 'alpha');

        $canonical = $caps->toCanonical();

        self::assertSame(['reasoning', 'tool-use', 'vision'], $canonical['cases']);
        self::assertSame(['alpha', 'zebra'], $canonical['custom']);
    }

    #[Test]
    public function unionCombinesTwoSets(): void
    {
        $a = Capabilities::of(Capability::Reasoning, Capability::ToolUse);
        $b = Capabilities::of(Capability::Vision, Capability::JsonMode);

        $merged = $a->union($b);

        self::assertTrue($merged->has(Capability::Reasoning));
        self::assertTrue($merged->has(Capability::ToolUse));
        self::assertTrue($merged->has(Capability::Vision));
        self::assertTrue($merged->has(Capability::JsonMode));
    }

    #[Test]
    public function unionDedupsOverlap(): void
    {
        $a = Capabilities::of(Capability::Reasoning, Capability::Vision);
        $b = Capabilities::of(Capability::Vision, Capability::ToolUse);

        $merged = $a->union($b);

        self::assertCount(3, $merged->cases, 'overlap (Vision) should appear once');
    }

    #[Test]
    public function unionPreservesCustomTagsFromBoth(): void
    {
        $a = Capabilities::of(Capability::Reasoning)->withCustom('feature-a');
        $b = Capabilities::of(Capability::Vision)->withCustom('feature-b');

        $merged = $a->union($b);

        self::assertTrue($merged->hasCustom('feature-a'));
        self::assertTrue($merged->hasCustom('feature-b'));
    }

    #[Test]
    public function emptyAndNonEmptyAreDetected(): void
    {
        self::assertTrue(Capabilities::empty()->isEmpty());
        self::assertFalse(Capabilities::of(Capability::Reasoning)->isEmpty());
        self::assertFalse(Capabilities::empty()->withCustom('feature-x')->isEmpty());
    }

    #[Test]
    public function withoutNonMemberIsNoOp(): void
    {
        $original = Capabilities::of(Capability::Reasoning);
        $reduced = $original->without(Capability::Vision);

        self::assertTrue($reduced->has(Capability::Reasoning));
        self::assertCount(1, $reduced->cases);
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(Capabilities::of(Capability::Reasoning, Capability::ToolUse));
        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    #[Test]
    public function differentCapabilitiesProduceDifferentHashes(): void
    {
        $a = Capabilities::of(Capability::Reasoning);
        $b = Capabilities::of(Capability::ToolUse);
        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }
}
