<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Capabilities::class)]
final class CapabilitiesTest extends TestCase
{
    public function test_of_creates_set_from_cases(): void
    {
        $caps = Capabilities::of(Capability::Reasoning, Capability::ToolUse);

        self::assertTrue($caps->has(Capability::Reasoning));
        self::assertTrue($caps->has(Capability::ToolUse));
        self::assertFalse($caps->has(Capability::Vision));
    }

    public function test_with_returns_new_instance(): void
    {
        $original = Capabilities::of(Capability::Reasoning);
        $extended = $original->with(Capability::ToolUse);

        self::assertNotSame($original, $extended);
        self::assertFalse($original->has(Capability::ToolUse));
        self::assertTrue($extended->has(Capability::ToolUse));
    }

    public function test_duplicates_are_deduped(): void
    {
        $caps = Capabilities::of(Capability::Reasoning, Capability::Reasoning, Capability::ToolUse);

        self::assertCount(2, $caps->cases);
    }

    public function test_satisfies_checks_all_required(): void
    {
        $caps = Capabilities::of(Capability::Reasoning, Capability::ToolUse, Capability::Vision);

        self::assertTrue($caps->satisfies(Capability::Reasoning, Capability::ToolUse));
        self::assertFalse($caps->satisfies(Capability::Reasoning, Capability::JsonMode));
    }

    public function test_intersect_preserves_only_shared(): void
    {
        $a = Capabilities::of(Capability::Reasoning, Capability::ToolUse, Capability::Vision);
        $b = Capabilities::of(Capability::Vision, Capability::JsonMode);

        $shared = $a->intersect($b);

        self::assertTrue($shared->has(Capability::Vision));
        self::assertFalse($shared->has(Capability::Reasoning));
        self::assertFalse($shared->has(Capability::JsonMode));
    }

    public function test_custom_tags_survive_with_and_without(): void
    {
        $caps = Capabilities::of(Capability::Reasoning)
            ->withCustom('vendor-feature-a', 'vendor-feature-b');

        self::assertTrue($caps->hasCustom('vendor-feature-a'));
        self::assertTrue($caps->hasCustom('vendor-feature-b'));

        $reduced = $caps->without(Capability::Reasoning);

        self::assertFalse($reduced->has(Capability::Reasoning));
        self::assertTrue($reduced->hasCustom('vendor-feature-a'));
    }

    public function test_to_canonical_sorts_cases_and_custom(): void
    {
        $caps = Capabilities::of(Capability::Vision, Capability::Reasoning, Capability::ToolUse)
            ->withCustom('zebra', 'alpha');

        $canonical = $caps->toCanonical();

        self::assertSame(['reasoning', 'tool-use', 'vision'], $canonical['cases']);
        self::assertSame(['alpha', 'zebra'], $canonical['custom']);
    }

    public function test_union_combines_two_sets(): void
    {
        $a = Capabilities::of(Capability::Reasoning, Capability::ToolUse);
        $b = Capabilities::of(Capability::Vision, Capability::JsonMode);

        $merged = $a->union($b);

        self::assertTrue($merged->has(Capability::Reasoning));
        self::assertTrue($merged->has(Capability::ToolUse));
        self::assertTrue($merged->has(Capability::Vision));
        self::assertTrue($merged->has(Capability::JsonMode));
    }

    public function test_union_dedups_overlap(): void
    {
        $a = Capabilities::of(Capability::Reasoning, Capability::Vision);
        $b = Capabilities::of(Capability::Vision, Capability::ToolUse);

        $merged = $a->union($b);

        self::assertCount(3, $merged->cases, 'overlap (Vision) should appear once');
    }

    public function test_union_preserves_custom_tags_from_both(): void
    {
        $a = Capabilities::of(Capability::Reasoning)->withCustom('feature-a');
        $b = Capabilities::of(Capability::Vision)->withCustom('feature-b');

        $merged = $a->union($b);

        self::assertTrue($merged->hasCustom('feature-a'));
        self::assertTrue($merged->hasCustom('feature-b'));
    }

    public function test_is_empty(): void
    {
        self::assertTrue(Capabilities::empty()->isEmpty());
        self::assertFalse(Capabilities::of(Capability::Reasoning)->isEmpty());
        self::assertFalse(Capabilities::empty()->withCustom('feature-x')->isEmpty());
    }

    public function test_without_non_member_is_no_op(): void
    {
        $original = Capabilities::of(Capability::Reasoning);
        $reduced  = $original->without(Capability::Vision);

        self::assertTrue($reduced->has(Capability::Reasoning));
        self::assertCount(1, $reduced->cases);
    }
}
