<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\Integration\LensRequiresBundle;

use Phalanx\Boot\AppContext;
use Phalanx\Http\Testing\HttpTestableBundle;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fixture sitting at a path containing /Integration/ — the
 * LensRequiresBundleRule is active here. Accessing $app->http after
 * registering HttpTestableBundle must NOT trigger the rule because
 * HttpTestableBundle::lens() declares HttpLens.
 */
final class HappyPath extends PhalanxTestCase
{
    #[Test]
    public function httpLensAvailableWhenHttpBundleRegistered(): void
    {
        $app = $this->testApp(new AppContext(), new HttpTestableBundle());

        // $app->http is backed by HttpLens which HttpTestableBundle::lens() declares.
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
