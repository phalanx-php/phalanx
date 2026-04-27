<?php

declare(strict_types=1);

namespace Phx\Agents\UI;

use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmEventKind;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\Style\Theme;
use Phalanx\Theatron\Widget\FluidPanel;

final class SwarmDashboard
{
    public function __construct(
        private readonly StreamOutput $output,
        private readonly Theme $theme,
        private readonly SwarmBus $bus,
        private readonly string $workspace,
    ) {}

    public function run(\Phalanx\ExecutionScope $scope): void
    {
        foreach ($this->bus->subscribe(['workspace' => $this->workspace, 'kinds' => SwarmEventKind::UiRender])($scope) as $event) {
            $this->render($event->payload);
        }
    }

    private function render(array $state): void
    {
        $w = $this->output->width();
        
        $statusLines = [];
        foreach ($state['agents'] as $name => $status) {
            $statusLines[] = sprintf("  %-10s | %-15s | %d tokens", $name, $status['status'], $status['tokens']);
        }

        $feedLines = [];
        foreach ($state['feed'] as $msg) {
            $feedLines[] = "[" . $this->theme->label->apply($msg['from']) . "] " . $msg['text'];
        }

        $outputLines = [
            (new FluidPanel("Phalanx Swarm Dashboard"))->render($w, ["  Active Workspace: " . $this->workspace]),
            (new FluidPanel("Agent Status"))->render($w, $statusLines),
            (new FluidPanel("Admin Synthesis"))->render($w, ["  " . ($state['synthesis'] ?? '...')]),
            (new FluidPanel("Conversation Feed"))->render($w, $feedLines),
        ];

        $this->output->update(...$outputLines);
    }
}
