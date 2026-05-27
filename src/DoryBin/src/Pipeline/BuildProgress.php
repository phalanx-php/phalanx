<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Pipeline;

use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\TaskList;
use Phalanx\Archon\Console\Widget\TaskState;

final class BuildProgress
{
    private TaskList $taskList;

    private int $tick = 0;

    public function __construct(
        private(set) StreamOutput $output,
    ) {
        $this->taskList = new TaskList(Theme::default());
    }

    public function registerStages(BuildStage ...$stages): void
    {
        foreach ($stages as $stage) {
            $this->taskList->add($stage->name, $stage->description);
        }
    }

    public function stageStarted(string $name): void
    {
        $this->taskList->setState($name, TaskState::Running);
        $this->render();
    }

    public function stageCompleted(StageResult $result): void
    {
        $state = match (true) {
            $result->skipped => TaskState::Skipped,
            $result->success => TaskState::Success,
            default => TaskState::Error,
        };

        $detail = $result->skipped ? 'cached' : sprintf('%.1fs', $result->durationMs / 1000);
        $this->taskList->setState($result->stageName, $state, $detail);
        $this->render();
    }

    private function render(): void
    {
        $this->output->update($this->taskList->render($this->tick++));
    }
}
