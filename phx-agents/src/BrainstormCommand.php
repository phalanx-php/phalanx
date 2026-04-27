<?php
declare(strict_types=1);

namespace Phx\Agents;

use Phalanx\Athena\Swarm\ChatAdminTask;
use Phalanx\Athena\Swarm\SwarmAgentTask;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\UiSupervisorTask;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\Style\Theme;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;
use Phx\Agents\Roles\AgenticAgent;
use Phx\Agents\Roles\StorageAgent;
use Phx\Agents\Roles\VisionAgent;
use Phx\Agents\UI\SwarmDashboard;

final readonly class BrainstormCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        $output = $scope->service(StreamOutput::class);
        $theme  = $scope->service(Theme::class);
        $bus    = $scope->service(SwarmBus::class);
        $config = $scope->service(SwarmConfig::class);

        $workers = [
            'VISION'   => new VisionAgent(),
            'STORAGE'  => new StorageAgent(),
            'AGENTIC'  => new AgenticAgent(),
        ];

        $tasks = [];
        foreach ($workers as $id => $agent) {
            $tasks["worker:$id"] = new SwarmAgentTask($id, $agent, $bus, $config);
        }

        $tasks['admin'] = new ChatAdminTask('ADMIN', new VisionAgent(), $bus, $config);
        $tasks['ui_supervisor'] = new UiSupervisorTask('UI_SUPERVISOR', $bus, $config);

        $scope->concurrent([
            'swarm' => Task::of(static fn(ExecutionScope $s) => $s->concurrent($tasks)),
            'ui'    => Task::of(static function (ExecutionScope $s) use ($output, $theme, $bus, $config) {
                $dashboard = new SwarmDashboard($output, $theme, $bus, $config->workspace);
                $dashboard->run($s);
            }),
        ]);

        return 0;
    }
}
