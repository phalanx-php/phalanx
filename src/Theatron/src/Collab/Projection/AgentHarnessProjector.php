<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Projection;

use Phalanx\Theatron\Collab\Events\AgentHarnessEvent;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Lifecycle\LoopStage;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Reviews\ReviewVerdict;
use Phalanx\Theatron\Collab\State\AgentHarnessStore;
use Phalanx\Theatron\Collab\State\ContextSlice;
use Phalanx\Theatron\Collab\State\LoopSlice;
use Phalanx\Theatron\Collab\State\MessageTimelineSlice;
use Phalanx\Theatron\Collab\State\ParticipantSlice;
use Phalanx\Theatron\Collab\State\ReviewSlice;
use Phalanx\Theatron\Collab\State\RuntimeSlice;
use Phalanx\Theatron\Collab\State\WorkPlanSlice;

final class AgentHarnessProjector
{
    public function __invoke(AgentHarnessEvent $event, AgentHarnessStore $store): AgentHarnessStore
    {
        $this->project($event, $store);

        return $store;
    }

    public function project(AgentHarnessEvent $event, AgentHarnessStore $store): AgentHarnessStore
    {
        $this->validate($event);

        $store->transaction(function () use ($event, $store): void {
            match ($event->kind) {
                EventKind::LoopAdvanced => null,
                EventKind::WorkReceived => $this->projectReceived($event, $store),
                EventKind::WorkPrepared => $this->projectPrepared($event, $store),
                EventKind::WorkDistributed,
                EventKind::WorkCompleted => null,
                EventKind::WorkItemStarted => $this->projectStarted($event, $store),
                EventKind::WorkItemCompleted,
                EventKind::WorkInterrupted => $this->projectResult($event, $store),
                EventKind::WorkReviewed => $this->projectReview($event, $store),
                EventKind::EffectRequested,
                EventKind::EffectApproved,
                EventKind::EffectDenied => throw new \InvalidArgumentException(sprintf(
                    'AgentHarness event "%s" is not supported by the alpha projector.',
                    $event->kind->value,
                )),
            };

            $this->projectStage($event, $store);
            $this->projectMetadata($event, $store);
        });

        return $store;
    }

    private static function stageFor(EventKind $kind): LoopStage
    {
        return match ($kind) {
            EventKind::LoopAdvanced => throw new \LogicException('Loop advanced events resolve stage from context.'),
            EventKind::WorkReceived => LoopStage::Receive,
            EventKind::WorkPrepared => LoopStage::Prepare,
            EventKind::WorkDistributed => LoopStage::Distribute,
            EventKind::WorkItemStarted,
            EventKind::WorkItemCompleted,
            EventKind::WorkInterrupted => LoopStage::Execute,
            EventKind::EffectRequested,
            EventKind::EffectApproved,
            EventKind::EffectDenied => LoopStage::React,
            EventKind::WorkReviewed => LoopStage::Review,
            EventKind::WorkCompleted => LoopStage::Complete,
        };
    }

    private function validate(AgentHarnessEvent $event): void
    {
        match ($event->kind) {
            EventKind::LoopAdvanced => $this->requireLoopStage($event),
            EventKind::WorkReceived => $this->validateReceived($event),
            EventKind::WorkPrepared,
            EventKind::WorkDistributed,
            EventKind::WorkCompleted => null,
            EventKind::WorkItemStarted => $this->requireWorkItem($event),
            EventKind::WorkItemCompleted,
            EventKind::WorkInterrupted => $this->validateResult($event),
            EventKind::WorkReviewed => $this->validateReview($event),
            EventKind::EffectRequested,
            EventKind::EffectApproved,
            EventKind::EffectDenied => throw new \InvalidArgumentException(sprintf(
                'AgentHarness event "%s" is not supported by the alpha projector.',
                $event->kind->value,
            )),
        };
    }

    private function projectReceived(AgentHarnessEvent $event, AgentHarnessStore $store): void
    {
        if ($event->envelope === null && $event->workItem === null) {
            return;
        }

        if ($event->workItem !== null) {
            $workItem = $this->requireWorkItem($event);

            $store->mutate(
                WorkPlanSlice::class,
                static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->append($workItem),
            );
        }

        if ($event->envelope !== null) {
            $store->mutate(
                MessageTimelineSlice::class,
                static fn (MessageTimelineSlice $slice): MessageTimelineSlice => $slice->project($event),
            );
        }
    }

    private function projectPrepared(AgentHarnessEvent $event, AgentHarnessStore $store): void
    {
        if ($event->workItem === null) {
            return;
        }

        $workItem = $this->requireWorkItem($event);

        $store->mutate(
            WorkPlanSlice::class,
            static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->append($workItem),
        );
    }

    private function projectStarted(AgentHarnessEvent $event, AgentHarnessStore $store): void
    {
        $workItem = $this->requireWorkItem($event);

        $store->mutate(
            WorkPlanSlice::class,
            static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->start($workItem->id),
        );

        $store->mutate(
            MessageTimelineSlice::class,
            static fn (MessageTimelineSlice $slice): MessageTimelineSlice => $slice->project($event),
        );
    }

    private function projectResult(AgentHarnessEvent $event, AgentHarnessStore $store): void
    {
        $workResult = $this->requireWorkResult($event);

        $store->mutate(
            WorkPlanSlice::class,
            static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->fulfill($workResult),
        );

        $store->mutate(
            MessageTimelineSlice::class,
            static fn (MessageTimelineSlice $slice): MessageTimelineSlice => $slice->project($event),
        );
    }

    private function projectReview(AgentHarnessEvent $event, AgentHarnessStore $store): void
    {
        $verdict = $this->requireReviewVerdict($event);

        if ($verdict->needsRevision()) {
            $store->mutate(
                WorkPlanSlice::class,
                static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->append(...$verdict->requiredWork),
            );
        }

        if ($verdict->isRejected()) {
            $store->mutate(
                WorkPlanSlice::class,
                static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->abort(
                    $verdict->reason ?? 'Review rejected the completed work.',
                ),
            );
        }

        $store->mutate(
            ReviewSlice::class,
            static fn (ReviewSlice $slice): ReviewSlice => $slice->record($verdict),
        );

        $store->mutate(
            MessageTimelineSlice::class,
            static fn (MessageTimelineSlice $slice): MessageTimelineSlice => $slice->project($event),
        );
    }

    private function projectStage(AgentHarnessEvent $event, AgentHarnessStore $store): void
    {
        $stage = $this->loopStage($event);

        $store->mutate(
            LoopSlice::class,
            static fn (LoopSlice $slice): LoopSlice => $slice->advance($stage),
        );
    }

    private function loopStage(AgentHarnessEvent $event): LoopStage
    {
        if ($event->kind === EventKind::LoopAdvanced) {
            return $this->requireLoopStage($event);
        }

        return self::stageFor($event->kind);
    }

    private function projectMetadata(AgentHarnessEvent $event, AgentHarnessStore $store): void
    {
        $this->projectRuntimeMetadata($event->context, $store);
        $this->projectContextMetadata($event->context, $store);
        $this->projectParticipantMetadata($event->context, $store);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function projectRuntimeMetadata(array $context, AgentHarnessStore $store): void
    {
        if (
            !array_key_exists('runtime_session_id', $context)
            && !array_key_exists('runtime_replaying', $context)
            && !array_key_exists('runtime_health', $context)
        ) {
            return;
        }

        $sessionId = $this->optionalString($context, 'runtime_session_id');
        $replaying = $this->optionalBool($context, 'runtime_replaying');
        $health = $this->optionalString($context, 'runtime_health');

        $store->mutate(
            RuntimeSlice::class,
            static fn (RuntimeSlice $slice): RuntimeSlice => $slice->update(
                sessionId: $sessionId,
                replaying: $replaying,
                health: $health,
            ),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function projectContextMetadata(array $context, AgentHarnessStore $store): void
    {
        if (
            !array_key_exists('context_pressure', $context)
            && !array_key_exists('context_active_focus', $context)
        ) {
            return;
        }

        $pressure = $this->optionalInt($context, 'context_pressure');
        $activeFocus = $this->optionalString($context, 'context_active_focus');

        $store->mutate(
            ContextSlice::class,
            static fn (ContextSlice $slice): ContextSlice => $slice->update(
                pressure: $pressure,
                activeFocus: $activeFocus,
            ),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function projectParticipantMetadata(array $context, AgentHarnessStore $store): void
    {
        if (!array_key_exists('participants', $context)) {
            return;
        }

        $participants = $context['participants'];
        if (!is_array($participants) || !array_is_list($participants)) {
            throw new \InvalidArgumentException('Projected participants context must be a list of strings.');
        }

        $ids = [];
        foreach ($participants as $participant) {
            if (!is_string($participant)) {
                throw new \InvalidArgumentException('Projected participants context must be a list of strings.');
            }

            $ids[] = $participant;
        }

        $store->mutate(
            ParticipantSlice::class,
            static fn (ParticipantSlice $slice): ParticipantSlice => $slice->register(...$ids),
        );
    }

    private function validateReceived(AgentHarnessEvent $event): void
    {
        if ($event->envelope === null && $event->workItem === null) {
            return;
        }

        if ($event->workItem !== null) {
            $this->requireEnvelope($event);
        }
    }

    private function validateResult(AgentHarnessEvent $event): void
    {
        $workItem = $this->requireWorkItem($event);
        $workResult = $this->requireWorkResult($event);

        if ($workItem->id !== $workResult->itemId) {
            throw new \InvalidArgumentException('Projected work result item id must match the event work item.');
        }

        if ($event->kind === EventKind::WorkItemCompleted && !$workResult->isDone()) {
            throw new \InvalidArgumentException('Completed work projection requires a done result.');
        }

        if ($event->kind === EventKind::WorkInterrupted && $workResult->isDone()) {
            throw new \InvalidArgumentException('Interrupted work projection requires a blocked or failed result.');
        }
    }

    private function validateReview(AgentHarnessEvent $event): void
    {
        $this->requireReviewVerdict($event);
    }

    private function requireEnvelope(AgentHarnessEvent $event): void
    {
        if ($event->envelope === null) {
            throw new \InvalidArgumentException(sprintf(
                'AgentHarness event "%s" requires an envelope for projection.',
                $event->kind->value,
            ));
        }
    }

    private function requireWorkItem(AgentHarnessEvent $event): WorkItem
    {
        return $event->workItem ?? throw new \InvalidArgumentException(sprintf(
            'AgentHarness event "%s" requires a work item for projection.',
            $event->kind->value,
        ));
    }

    private function requireWorkResult(AgentHarnessEvent $event): WorkResult
    {
        return $event->workResult ?? throw new \InvalidArgumentException(sprintf(
            'AgentHarness event "%s" requires a work result for projection.',
            $event->kind->value,
        ));
    }

    private function requireReviewVerdict(AgentHarnessEvent $event): ReviewVerdict
    {
        return $event->reviewVerdict ?? throw new \InvalidArgumentException(
            'AgentHarness event "work_reviewed" requires a review verdict for projection.',
        );
    }

    private function requireLoopStage(AgentHarnessEvent $event): LoopStage
    {
        $stage = $event->context['loop_stage'] ?? null;
        if (!is_string($stage)) {
            throw new \InvalidArgumentException('AgentHarness event "loop_advanced" requires a loop_stage context string.');
        }

        return LoopStage::tryFrom($stage) ?? throw new \InvalidArgumentException(sprintf(
            'AgentHarness event "loop_advanced" has unknown loop stage "%s".',
            $stage,
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function optionalString(array $context, string $key): ?string
    {
        if (!array_key_exists($key, $context)) {
            return null;
        }

        if (!is_string($context[$key])) {
            throw new \InvalidArgumentException(sprintf('Projected "%s" context must be a string.', $key));
        }

        return $context[$key];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function optionalBool(array $context, string $key): ?bool
    {
        if (!array_key_exists($key, $context)) {
            return null;
        }

        if (!is_bool($context[$key])) {
            throw new \InvalidArgumentException(sprintf('Projected "%s" context must be a bool.', $key));
        }

        return $context[$key];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function optionalInt(array $context, string $key): ?int
    {
        if (!array_key_exists($key, $context)) {
            return null;
        }

        if (!is_int($context[$key])) {
            throw new \InvalidArgumentException(sprintf('Projected "%s" context must be an int.', $key));
        }

        return $context[$key];
    }
}
