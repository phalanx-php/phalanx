<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance\LensRequiresBundle;

use Phalanx\Testing\PhalanxTestCase;

final class MissingBundle extends PhalanxTestCase
{
    public function httpLensWithoutHttpBundle(): void
    {
        $app = $this->testApp();

        $lens = $app->http;
    }
}
