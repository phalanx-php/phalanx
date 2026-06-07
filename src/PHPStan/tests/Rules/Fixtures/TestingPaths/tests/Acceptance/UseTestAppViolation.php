<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

use Phalanx\Application;
use Phalanx\DevServer\DevServer;
use Phalanx\Tui\Tui;

final class UseTestAppViolation
{
    public function bareApplication(): void
    {
        $app = Application::starting()->compile();
    }

    public function tuiRuntimeEntry(): void
    {
        $runtime = Tui::starting()->build();
    }

    public function tuiClassicEntry(): void
    {
        $app = Tui::app()->build();
    }

    public function devServerEntry(): void
    {
        $server = DevServer::starting()->build();
    }
}
