<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Apps;

use Phalanx\AppHost;
use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Tui\Apps\App;

final class Application
{
    public function __construct(
        private readonly AppHost $host,
        private readonly App $tui,
        private readonly float $tickIntervalSeconds,
    ) {
    }

    public function runtime(): AppHost
    {
        return $this->host;
    }

    public function host(): AppHost
    {
        return $this->host;
    }

    public function tui(): App
    {
        return $this->tui;
    }

    public function run(): int
    {
        $tui = $this->tui;
        $interval = $this->tickIntervalSeconds;

        $this->host->run(static function (ExecutionScope $scope) use ($tui, $interval): void {
            $runtime = $scope->service(Runtime::class);
            if (!$runtime instanceof Runtime) {
                throw new \RuntimeException('TUI runtime service did not resolve.');
            }

            $scope->periodic(Mark::s($interval), static function () use ($runtime, $scope): void {
                $runtime->tick($scope);
            });

            $tui->start($scope);
        });

        return 0;
    }
}
