<?php

declare(strict_types=1);

$agentHarnessAliases = [
    'Phalanx\\Theatron\\AgentHarness\\Apps\\AgentHarnessBuilder' => Phalanx\Theatron\Collab\Apps\AgentHarnessBuilder::class,
    'Phalanx\\Theatron\\AgentHarness\\Apps\\AgentHarnessRuntime' => Phalanx\Theatron\Collab\Apps\AgentHarnessRuntime::class,
    'Phalanx\\Theatron\\AgentHarness\\Apps\\AgentHarnessServiceBundle' => Phalanx\Theatron\Collab\Apps\AgentHarnessServiceBundle::class,
    'Phalanx\\Theatron\\AgentHarness\\Boundaries\\Inlet' => Phalanx\Theatron\Collab\Boundaries\Inlet::class,
    'Phalanx\\Theatron\\AgentHarness\\Boundaries\\InletChannel' => Phalanx\Theatron\Collab\Boundaries\InletChannel::class,
    'Phalanx\\Theatron\\AgentHarness\\Boundaries\\InletMessage' => Phalanx\Theatron\Collab\Boundaries\InletMessage::class,
    'Phalanx\\Theatron\\AgentHarness\\Boundaries\\InletQueue' => Phalanx\Theatron\Collab\Boundaries\InletQueue::class,
    'Phalanx\\Theatron\\AgentHarness\\Boundaries\\InputPromptSubmitter' => Phalanx\Theatron\Collab\Boundaries\InputPromptSubmitter::class,
    'Phalanx\\Theatron\\AgentHarness\\Boundaries\\Outlet' => Phalanx\Theatron\Collab\Boundaries\Outlet::class,
    'Phalanx\\Theatron\\AgentHarness\\Boundaries\\Urgency' => Phalanx\Theatron\Collab\Boundaries\Urgency::class,
    'Phalanx\\Theatron\\AgentHarness\\Effects\\EffectStatus' => Phalanx\Theatron\Collab\Effects\EffectStatus::class,
    'Phalanx\\Theatron\\AgentHarness\\Events\\AgentHarnessEvent' => Phalanx\Theatron\Collab\Events\AgentHarnessEvent::class,
    'Phalanx\\Theatron\\AgentHarness\\Events\\EventKind' => Phalanx\Theatron\Collab\Events\EventKind::class,
    'Phalanx\\Theatron\\AgentHarness\\Events\\RoutableEvent' => Phalanx\Theatron\Collab\Events\RoutableEvent::class,
    'Phalanx\\Theatron\\AgentHarness\\Lifecycle\\AgentHarnessLoop' => Phalanx\Theatron\Collab\Lifecycle\AgentHarnessLoop::class,
    'Phalanx\\Theatron\\AgentHarness\\Lifecycle\\LoopStage' => Phalanx\Theatron\Collab\Lifecycle\LoopStage::class,
    'Phalanx\\Theatron\\AgentHarness\\Messages\\Address' => Phalanx\Theatron\Collab\Messages\Address::class,
    'Phalanx\\Theatron\\AgentHarness\\Messages\\Envelope' => Phalanx\Theatron\Collab\Messages\Envelope::class,
    'Phalanx\\Theatron\\AgentHarness\\Messages\\MessageKind' => Phalanx\Theatron\Collab\Messages\MessageKind::class,
    'Phalanx\\Theatron\\AgentHarness\\Participants\\AgentParticipant' => Phalanx\Theatron\Collab\Participants\AgentParticipant::class,
    'Phalanx\\Theatron\\AgentHarness\\Participants\\Preparer' => Phalanx\Theatron\Collab\Participants\Preparer::class,
    'Phalanx\\Theatron\\AgentHarness\\Participants\\Reactor' => Phalanx\Theatron\Collab\Participants\Reactor::class,
    'Phalanx\\Theatron\\AgentHarness\\Participants\\Reviewer' => Phalanx\Theatron\Collab\Participants\Reviewer::class,
    'Phalanx\\Theatron\\AgentHarness\\Plans\\Activity' => Phalanx\Theatron\Collab\Plans\Activity::class,
    'Phalanx\\Theatron\\AgentHarness\\Plans\\WorkItem' => Phalanx\Theatron\Collab\Plans\WorkItem::class,
    'Phalanx\\Theatron\\AgentHarness\\Plans\\WorkItemStatus' => Phalanx\Theatron\Collab\Plans\WorkItemStatus::class,
    'Phalanx\\Theatron\\AgentHarness\\Plans\\WorkPlan' => Phalanx\Theatron\Collab\Plans\WorkPlan::class,
    'Phalanx\\Theatron\\AgentHarness\\Plans\\WorkPlanItem' => Phalanx\Theatron\Collab\Plans\WorkPlanItem::class,
    'Phalanx\\Theatron\\AgentHarness\\Plans\\WorkPlanStatus' => Phalanx\Theatron\Collab\Plans\WorkPlanStatus::class,
    'Phalanx\\Theatron\\AgentHarness\\Plans\\WorkResult' => Phalanx\Theatron\Collab\Plans\WorkResult::class,
    'Phalanx\\Theatron\\AgentHarness\\Plans\\WorkResultStatus' => Phalanx\Theatron\Collab\Plans\WorkResultStatus::class,
    'Phalanx\\Theatron\\AgentHarness\\Projection\\AgentHarnessProjector' => Phalanx\Theatron\Collab\Projection\AgentHarnessProjector::class,
    'Phalanx\\Theatron\\AgentHarness\\Projection\\AgentHarnessReplay' => Phalanx\Theatron\Collab\Projection\AgentHarnessReplay::class,
    'Phalanx\\Theatron\\AgentHarness\\Prompts\\FilePrompt' => Phalanx\Theatron\Collab\Prompts\FilePrompt::class,
    'Phalanx\\Theatron\\AgentHarness\\Prompts\\PromptSource' => Phalanx\Theatron\Collab\Prompts\PromptSource::class,
    'Phalanx\\Theatron\\AgentHarness\\Reviews\\ReviewStatus' => Phalanx\Theatron\Collab\Reviews\ReviewStatus::class,
    'Phalanx\\Theatron\\AgentHarness\\Reviews\\ReviewVerdict' => Phalanx\Theatron\Collab\Reviews\ReviewVerdict::class,
    'Phalanx\\Theatron\\AgentHarness\\State\\AgentHarnessStore' => Phalanx\Theatron\Collab\State\AgentHarnessStore::class,
    'Phalanx\\Theatron\\AgentHarness\\State\\TimelineEntry' => Phalanx\Theatron\Collab\State\TimelineEntry::class,
    'Phalanx\\Theatron\\AgentHarness\\State\\TimelineEntryKind' => Phalanx\Theatron\Collab\State\TimelineEntryKind::class,
    'Phalanx\\Theatron\\AgentHarness\\WorkContext' => Phalanx\Theatron\Collab\WorkContext::class,
];

foreach ($agentHarnessAliases as $alias => $original) {
    class_alias($original, $alias);
}
