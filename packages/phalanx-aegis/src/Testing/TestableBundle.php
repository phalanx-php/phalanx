<?php

declare(strict_types=1);

namespace Phalanx\Testing;

/**
 * Opt-in marker for ServiceBundles that contribute a test lens.
 *
 * Bundles list the lens classes they activate. Both the codegen plugin
 * and TestApp reflect each lens for #[\Phalanx\Testing\Attribute\Lens]
 * to recover the factory, accessor name, return type, and requirements —
 * a single source of truth shared by build-time codegen and runtime
 * registration.
 *
 * Existing ServiceBundles are unaffected — adoption is purely additive.
 */
interface TestableBundle
{
    /** @return list<class-string<Lens>> */
    public static function testLenses(): array;
}
