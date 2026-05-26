<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

use InvalidArgumentException;

final class ActivitySlice
{
    public function __construct(
        private(set) ActivityStatus $status = ActivityStatus::Idle,
        private(set) ?PendingEffect $pendingEffect = null,
        private(set) int $inputTokens = 0,
        private(set) int $outputTokens = 0,
        private(set) int $totalTokens = 0,
        private(set) string $modelName = 'none',
        private(set) int $pulseFrame = 0,
    ) {
    }

    public function awaitingApproval(PendingEffect $effect): self
    {
        return new self(
            status: ActivityStatus::AwaitingApproval,
            pendingEffect: $effect,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            totalTokens: $this->totalTokens,
            modelName: $this->modelName,
            pulseFrame: $this->pulseFrame,
        );
    }

    public function effectResolved(): self
    {
        return new self(
            status: ActivityStatus::Running,
            pendingEffect: null,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            totalTokens: $this->totalTokens,
            modelName: $this->modelName,
            pulseFrame: $this->pulseFrame,
        );
    }

    public function activityEnded(ActivityStatus $terminal): self
    {
        if (
            $terminal !== ActivityStatus::Completed
            && $terminal !== ActivityStatus::Failed
            && $terminal !== ActivityStatus::Cancelled
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Status "%s" is not a terminal state; expected Completed, Failed, or Cancelled.',
                    $terminal->name,
                ),
            );
        }

        return new self(
            status: $terminal,
            pendingEffect: $this->pendingEffect,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            totalTokens: $this->totalTokens,
            modelName: $this->modelName,
            pulseFrame: $this->pulseFrame,
        );
    }

    public function updateUsage(int $inputTokens, int $outputTokens): self
    {
        $newInput = $this->inputTokens + $inputTokens;
        $newOutput = $this->outputTokens + $outputTokens;

        return $this->withUsage($newInput, $newOutput);
    }

    public function withUsage(int $inputTokens, int $outputTokens): self
    {
        return new self(
            status: $this->status,
            pendingEffect: $this->pendingEffect,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $inputTokens + $outputTokens,
            modelName: $this->modelName,
            pulseFrame: $this->pulseFrame,
        );
    }

    public function withStatus(ActivityStatus $status): self
    {
        return new self(
            status: $status,
            pendingEffect: $this->pendingEffect,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            totalTokens: $this->totalTokens,
            modelName: $this->modelName,
            pulseFrame: 0,
        );
    }

    public function withModelName(string $modelName): self
    {
        return new self(
            status: $this->status,
            pendingEffect: $this->pendingEffect,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            totalTokens: $this->totalTokens,
            modelName: $modelName,
            pulseFrame: $this->pulseFrame,
        );
    }

    public function tick(): self
    {
        return new self(
            status: $this->status,
            pendingEffect: $this->pendingEffect,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            totalTokens: $this->totalTokens,
            modelName: $this->modelName,
            pulseFrame: $this->pulseFrame + 1,
        );
    }

    public function isBusy(): bool
    {
        return $this->status === ActivityStatus::Running || $this->status === ActivityStatus::AwaitingApproval;
    }
}
