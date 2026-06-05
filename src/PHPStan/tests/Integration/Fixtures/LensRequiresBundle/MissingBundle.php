<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\Integration\LensRequiresBundle;

use Phalanx\Boot\AppContext;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fixture sitting at a path containing /Integration/ — the
 * LensRequiresBundleRule is active here. Accessing $app->http without
 * registering TestableBundle MUST trigger the rule because no
 * bundle in the testApp() call declares Lens.
 */
final class MissingBundle extends PhalanxTestCase
{
    #[Test]
    public function httpLensWithoutHttpBundle(): void
    {
        // No TestableBundle passed — Lens is not in any bundle's lens() declaration.
        $app = $this->testApp(new AppContext());

        $lens = $app->http;
    }
}
