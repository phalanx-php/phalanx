<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Adapters\Athena;

use Phalanx\Athena\Activity\Config;
use Phalanx\Athena\Activity\State;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Id;
use Phalanx\Theatron\Collab\Messages\Address;
use Phalanx\Theatron\Collab\Participants\AgentParticipant;
use Phalanx\Theatron\Collab\Plans\WorkPlanItem;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\WorkContext;

final class AthenaAgentParticipant implements AgentParticipant
{
    /** @var list<\Phalanx\Athena\Hook\StepHook> */
    private array $hooks;

    /**
     * @param list<\Phalanx\Athena\Hook\StepHook> $hooks
     */
    public function __construct(
        private Agent $agent,
        private ?Context $context = null,
        private int $maxInvocations = 3,
        private ?float $timeoutSeconds = null,
        array $hooks = [],
        private AthenaRunner $runner = new StaticAthenaRunner(),
    ) {
        foreach ($hooks as $hook) {
            if (!$hook instanceof \Phalanx\Athena\Hook\StepHook) {
                throw new \InvalidArgumentException('Athena participant hooks must be StepHook instances.');
            }
        }

        $this->hooks = $hooks;
    }

    public function __invoke(WorkPlanItem $item, WorkContext $ctx): WorkResult
    {
        $result = ($this->runner)(
            $ctx->scope,
            $this->agent,
            $this->config($item),
            self::log($item),
        );

        /** @var list<Cue> $cues */
        $cues = $result->stream->toArray();

        return match ($result->state) {
            State::Completed => WorkResult::done(
                itemId: $item->workItem->id,
                payload: self::payload($result, $cues),
                summary: self::summary($result),
            ),
            State::Suspended => WorkResult::blocked(
                $item->workItem->id,
                'Athena activity is waiting for approval.',
            ),
            State::Cancelled => throw new Cancelled('Athena activity was cancelled.'),
            State::Failed => WorkResult::failed(
                $item->workItem->id,
                $result->error ?? new \RuntimeException(sprintf('Athena activity ended with %s.', $result->state->value)),
            ),
            default => WorkResult::failed(
                $item->workItem->id,
                new \RuntimeException(sprintf('Athena activity ended without a terminal result: %s.', $result->state->value)),
            ),
        };
    }

    public function supports(WorkPlanItem $item, WorkContext $ctx): bool
    {
        $preferred = $item->workItem->preferredParticipant;

        return $preferred === null
            || $preferred->equals(Address::agent($this->agent->id))
            || $preferred->toString() === $this->agent->id
            || $preferred->toString() === 'agent:' . $this->agent->id;
    }

    private static function log(WorkPlanItem $item): Log
    {
        return Log::from([
            new Message(
                id: 'msg_' . Id::generate(),
                sequence: 1,
                at: new \DateTimeImmutable(),
                role: 'user',
                text: $item->workItem->prompt,
            ),
        ]);
    }

    /**
     * @param list<Cue> $cues
     * @return array<string, mixed>
     */
    private static function payload(\Phalanx\Athena\Activity\Result $result, array $cues): array
    {
        return [
            'activity_id' => $result->activityId,
            'state' => $result->state->value,
            'outcome' => $result->outcome->value,
            'invocations' => $result->invocations,
            'cues' => array_map(static fn(Cue $cue): array => $cue->toCanonical(), $cues),
        ];
    }

    private static function summary(\Phalanx\Athena\Activity\Result $result): string
    {
        $records = array_reverse($result->log->toArray());
        foreach ($records as $record) {
            if ($record instanceof Message && $record->role === 'assistant') {
                return $record->text;
            }
        }

        return $result->outcome->value;
    }

    private function config(WorkPlanItem $item): Config
    {
        return new Config(
            id: $item->workItem->id,
            context: $this->context ?? $this->agent->context,
            maxInvocations: $this->maxInvocations,
            timeoutSeconds: $this->timeoutSeconds,
            hooks: $this->hooks,
        );
    }
}
