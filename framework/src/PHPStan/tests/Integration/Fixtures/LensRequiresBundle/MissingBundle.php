<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\Integration\LensRequiresBundle;

use Phalanx\Boot\AppContext;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fixture sitting at a path containing /Integration/ — the
 * LensRequiresBundleRule is active here. Accessing $app->http without
 * registering StoaTestableBundle MUST trigger the rule because no
 * bundle in the testApp() call declares HttpLens.
 */
final class MissingBundle extends PhalanxTestCase
{
    #[Test]
    public function httpLensWithoutStoaBundle(): void
    {
        // No StoaTestableBundle passed — HttpLens is not in any bundle's lens() declaration.
        $app = $this->testApp(new AppContext());

        $lens = $app->http;
    }
}
