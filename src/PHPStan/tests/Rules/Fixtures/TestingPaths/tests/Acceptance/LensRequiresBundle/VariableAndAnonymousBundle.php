<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance\LensRequiresBundle;

use Phalanx\Boot\AppContext;
use Phalanx\Http\Testing\Lens;
use Phalanx\Http\Testing\TestableBundle;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestLens;

final class VariableAndAnonymousBundle extends PhalanxTestCase
{
    public function variableBundleActivatesLens(): void
    {
        $bundle = new TestableBundle();

        $app = $this->testApp([], $bundle);

        $lens = $app->http;
    }

    public function namedVariadicBundleActivatesLens(): void
    {
        $bundle = new TestableBundle();

        $app = $this->testApp(context: [], bundles: $bundle);

        $lens = $app->http;
    }

    public function anonymousBundleActivatesLens(): void
    {
        $app = $this->testApp([], new class extends ServiceBundle {
            #[\Override]
            public static function lens(): TestLens
            {
                return TestLens::of(Lens::class);
            }

            #[\Override]
            public function services(Services $services, AppContext $context): void
            {
            }
        });

        $lens = $app->http;
    }
}
