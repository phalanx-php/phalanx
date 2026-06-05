<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\Integration\LensRequiresBundle;

use Phalanx\Boot\AppContext;
use Phalanx\Http\Testing\TestableBundle;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fixture sitting at a path containing /Integration/ — the
 * LensRequiresBundleRule is active here. Accessing $app->http after
 * registering TestableBundle must NOT trigger the rule because
 * TestableBundle::lens() declares Lens.
 */
final class HappyPath extends PhalanxTestCase
{
    #[Test]
    public function httpLensAvailableWhenHttpBundleRegistered(): void
    {
        $app = $this->testApp(new AppContext(), new \Phalanx\Http\Testing\TestableBundle());

        // $app->http is backed by Lens which TestableBundle::lens() declares.
        // No rule violation expected on this line.
        $lens = $app->http;
    }

    #[Test]
    public function runtimeNativeLensAlwaysAvailable(): void
    {
        // ledger, scope, runtime are Runtime-native — no bundle required, never flagged.
        $app = $this->testApp(new AppContext());

        $ledger  = $app->ledger;
        $scope   = $app->scope;
        $runtime = $app->runtime;
    }
}
