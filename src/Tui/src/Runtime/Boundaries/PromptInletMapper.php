<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Boundaries;

use Phalanx\Tui\Runtime\Messages\MessageKind;
use Phalanx\Tui\Runtime\Plans\Activity;
use Phalanx\Tui\Runtime\Plans\WorkItem;

final class PromptInletMapper
{
    public function __invoke(InletMessage $message): WorkItem
    {
        $envelope = $message->envelope;

        if ($envelope->kind !== MessageKind::Prompt || !is_string($envelope->payload)) {
            throw new \LogicException('Only prompt messages can be mapped into work items.');
        }

        return new WorkItem(
            activity: Activity::Thinking,
            prompt: $envelope->payload,
            tags: $envelope->tags,
            preferredParticipant: $envelope->to,
            priority: max($envelope->priority, $message->urgency->priority()),
        );
    }
}
