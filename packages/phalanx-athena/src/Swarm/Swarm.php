<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Athena\AgentDefinition;
use Phalanx\ExecutionScope;
use Phalanx\Styx\Emitter;
use Phalanx\Task\Task;
use Phalanx\Styx\Channel;
use Phalanx\Stream\Contract\StreamContext;

final readonly class Swarm
{
    /** @param array<string, AgentDefinition> $agents */
    public function __construct(
        private array $agents,
        private ?SwarmBus $bus = null,
        private ?SwarmConfig $config = null,
    ) {}

    public function run(ExecutionScope $scope): Emitter
    {
        $bus = $this->bus ?? new InMemorySwarmBus();
        $config = $this->config ?? new SwarmConfig(workspace: 'default', session: 'default');
        $agents = $this->agents;

        return Emitter::produce(static function (Channel $out, StreamContext $ctx) use ($scope, $bus, $config, $agents): void {
            $tasks = [];
            foreach ($agents as $id => $agent) {
                $tasks[$id] = Task::of(static function (ExecutionScope $es) use ($id, $agent, $bus, $config): mixed {
                    $task = new SwarmAgentTask($id, $agent, $bus, $config);
                    return $es->execute($task);
                });
            }

            // Spawning workers and relay in the background.
            $scope->concurrent(...[
                'workers' => Task::of(static function (ExecutionScope $es) use ($tasks): void {
                    $es->concurrent(...$tasks);
                }),
                'relay' => Task::of(static function (ExecutionScope $es) use ($bus, $config, $out): void {
                    foreach ($bus->subscribe(['workspace' => $config->workspace])($es) as $event) {
                        $out->emit($event);
                    }
                })
            ]);
        });
    }

    public function bus(): SwarmBus
    {
        return $this->bus ?? new InMemorySwarmBus();
    }
}
