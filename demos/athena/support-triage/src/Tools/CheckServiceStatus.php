<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope\Scope;

final class CheckServiceStatus implements Tool
{
    public string $description {
        get => 'Check if any services are currently experiencing issues';
    }

    public function __construct()
    {
    }

    public function __invoke(Scope $scope): ToolOutcome
    {
        return ToolOutcome::data([
            'active_incidents' => [],
            'degraded_services' => [],
            'all_operational' => true,
        ]);
    }
}
