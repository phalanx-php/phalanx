<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Support\Fixtures;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\Required;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class ApplicationBuilderBootReportBundle extends ServiceBundle
{
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(Required::env('CRITICAL_KEY'));
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}
