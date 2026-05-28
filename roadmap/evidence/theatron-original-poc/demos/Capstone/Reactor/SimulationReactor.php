<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Reactor;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Demos\Capstone\Event\AgentMessageEvent;
use Phalanx\Theatron\Demos\Capstone\Event\AgentOnlineEvent;
use Phalanx\Theatron\Demos\Capstone\Event\TaskCompletedEvent;
use Phalanx\Theatron\Demos\Capstone\Event\TaskDelegatedEvent;
use Phalanx\Theatron\Reactor\BackgroundReactor;
use Phalanx\Theatron\Reactor\ReactorExclusivity;
use Phalanx\Theatron\Reactor\ReactorState;
use Phalanx\Theatron\Stream\TheatronStream;

final class SimulationReactor implements BackgroundReactor
{
    public string $id { get => 'capstone.simulation'; }
    public ?string $group { get => null; }
    public ReactorState $state { get => $this->currentState; }
    public ReactorExclusivity $exclusivity { get => ReactorExclusivity::Concurrent; }

    private ReactorState $currentState = ReactorState::Idle;

    /** @var list<array{string, string, string}> */
    private array $agents = [
        ['researcher', 'Archimedes', 'research'],
        ['analyst', 'Thales', 'analysis'],
        ['steward', 'Pericles', 'coordination'],
    ];

    /** @var list<string> */
    private array $messages = [
        'Analyzing the strategic position of the eastern flank.',
        'Research complete: found 3 relevant historical precedents.',
        'Delegating reconnaissance to the analyst.',
        'The data suggests a defensive formation is optimal.',
        'Cross-referencing with Thermopylae deployment patterns.',
        'Task complete. Recommending phalanx formation at narrow pass.',
        'Synthesizing findings into a cohesive strategy.',
        'Logistics assessment: supply lines secure for 7 days.',
    ];

    /** @var list<string> */
    private array $taskTitles = [
        'Analyze terrain data',
        'Research historical precedents',
        'Compile intelligence report',
        'Evaluate formation options',
        'Draft strategic recommendation',
    ];

    public function start(ExecutionScope $scope, TheatronStream $stream): void
    {
        if ($this->currentState === ReactorState::Running) {
            return;
        }

        $this->currentState = ReactorState::Running;
        $reactor = $this;

        $scope->go(static function () use ($reactor, $scope, $stream): void {
            $reactor->simulate($scope, $stream);
        }, 'reactor.capstone.simulation');
    }

    public function cancel(): void
    {
        $this->currentState = ReactorState::Cancelled;
    }

    private function simulate(ExecutionScope $scope, TheatronStream $stream): void
    {
        try {
            foreach ($this->agents as [$id, $name, $_role]) {
                $stream->emit(new AgentOnlineEvent(agentId: $id, agentName: $name));
                $scope->delay(0.3);
            }

            $taskIndex = 0;
            $messageIndex = 0;

            while ($this->currentState === ReactorState::Running) {
                $scope->delay(1.5 + (mt_rand(0, 100) / 100.0));

                if ($this->currentState !== ReactorState::Running) {
                    return;
                }

                $action = mt_rand(0, 2);

                if ($action === 0 && $taskIndex < count($this->taskTitles)) {
                    $assignee = $this->agents[mt_rand(0, 1)][0];
                    $stream->emit(new TaskDelegatedEvent(
                        taskId: "task-{$taskIndex}",
                        title: $this->taskTitles[$taskIndex],
                        assignedTo: $assignee,
                    ));
                    $taskIndex++;
                } elseif ($action === 1 && $taskIndex > 0) {
                    $completeIdx = mt_rand(0, $taskIndex - 1);
                    $agentId = $this->agents[mt_rand(0, 2)][0];
                    $stream->emit(new TaskCompletedEvent(
                        taskId: "task-{$completeIdx}",
                        agentId: $agentId,
                        result: 'Analysis complete.',
                    ));
                } else {
                    $agentId = $this->agents[mt_rand(0, 2)][0];
                    $msg = $this->messages[$messageIndex % count($this->messages)];
                    $stream->emit(new AgentMessageEvent(
                        agentId: $agentId,
                        body: $msg,
                        timestamp: microtime(true),
                    ));
                    $messageIndex++;
                }
            }
        } catch (Cancelled) {
            $this->currentState = ReactorState::Cancelled;
        }
    }
}
