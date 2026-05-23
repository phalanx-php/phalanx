<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\Integration\LensRequiresBundle;

use Phalanx\Boot\AppContext;
use Phalanx\Stoa\Testing\StoaTestableBundle;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fixture sitting at a path containing /Integration/ — the
 * LensRequiresBundleRule is active here. Accessing $app->http after
 * registering StoaTestableBundle must NOT trigger the rule because
 * StoaTestableBundle::lens() declares HttpLens.
 */
final class HappyPath extends PhalanxTestCase
{
    #[Test]
    public function httpLensAvailableWhenStoaBundleRegistered(): void
    {
        $app = $this->testApp(new AppContext(), new StoaTestableBundle());

        // $app->http is backed by HttpLens which StoaTestableBundle::lens() declares.
        // No rule violation expected on this line.
        $lens = $app->http;
    }

    #[Test]
    public function aegisNativeLensAlwaysAvailable(): void
    {
        // ledger, scope, runtime are Aegis-native — no bundle required, never flagged.
        $app = $this->testApp(new AppContext());

        $ledger  = $app->ledger;
        $scope   = $app->scope;
        $runtime = $app->runtime;
    }
}
