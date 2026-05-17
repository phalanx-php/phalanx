<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider;

use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Provider\Needs;
use Phalanx\Panoply\Provider\Preference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Needs::class)]
final class NeedsTest extends TestCase
{
    public function test_new_is_empty(): void
    {
        self::assertTrue(Needs::new()->isEmpty());
    }

    public function test_prefer_places_preference_first(): void
    {
        $needs = Needs::new()->prefer(Preference::LocalFirst);

        self::assertSame(Preference::LocalFirst, $needs->preferences[0]);
    }

    public function test_fallback_appends_to_end(): void
    {
        $needs = Needs::new()
            ->prefer(Preference::LocalFirst)
            ->fallback(Preference::Hosted);

        self::assertSame(Preference::LocalFirst, $needs->preferences[0]);
        self::assertSame(Preference::Hosted, $needs->preferences[1]);
    }

    public function test_prefer_reorders_existing_preference_to_front(): void
    {
        $needs = Needs::new()
            ->fallback(Preference::Hosted)
            ->prefer(Preference::LocalFirst)
            ->prefer(Preference::Hosted);

        self::assertSame(Preference::Hosted, $needs->preferences[0]);
        self::assertSame(Preference::LocalFirst, $needs->preferences[1]);
    }

    public function test_require_accumulates_capabilities(): void
    {
        $needs = Needs::new()
            ->require(Capability::Reasoning)
            ->require(Capability::ToolUse);

        self::assertTrue($needs->required->has(Capability::Reasoning));
        self::assertTrue($needs->required->has(Capability::ToolUse));
    }

    public function test_immutability(): void
    {
        $original = Needs::new();
        $extended = $original->prefer(Preference::LocalFirst);

        self::assertNotSame($original, $extended);
        self::assertTrue($original->isEmpty());
    }
}
