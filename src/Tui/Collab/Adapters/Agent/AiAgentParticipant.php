<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Adapters\Agent;

use Phalanx\Agent\Activity\Config;
use Phalanx\Agent\Activity\State;
use Phalanx\Cancellation\Cancelled;
use Phalanx\AiProviders\Agent as AiAgent;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Conversation\Record\Message;
use Phalanx\AiProviders\Cue;
use Phalanx\AiProviders\Id;
use Phalanx\Tui\Collab\Messages\Address;
use Phalanx\Tui\Collab\Participants\AgentParticipant;
use Phalanx\Tui\Collab\Plans\WorkPlanItem;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\WorkContext;

final class AiAgentParticipant implements AgentParticipant
{
    /** @var list<\Phalanx\Agent\Hook\StepHook> */
    private array $hooks;

    /**
     * @param list<\Phalanx\Agent\Hook\StepHook> $hooks
     */
    public function __construct(
        private AiAgent $agent,
        private ?Context $context = null,
        private int $maxInvocations = 3,
        private ?float $timeoutSeconds = null,
        array $hooks = [],
        private AgentRunner $runner = new StaticAgentRunner(),
    ) {
        foreach ($hooks as $hook) {
            if (!$hook instanceof \Phalanx\Agent\Hook\StepHook) {
                throw new \InvalidArgumentException('Agent participant hooks must be StepHook instances.');
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
                'Agent activity is waiting for approval.',
            ),
            State::Cancelled => throw new Cancelled('Agent activity was cancelled.'),
            State::Failed => WorkResult::failed(
                $item->workItem->id,
                $result->error ?? new \RuntimeException(sprintf('Agent activity ended with %s.', $result->state->value)),
            ),
            default => WorkResult::failed(
                $item->workItem->id,
                new \RuntimeException(sprintf('Agent activity ended without a terminal result: %s.', $result->state->value)),
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
    private static function payload(\Phalanx\Agent\Activity\Result $result, array $cues): array
    {
        return [
            'activity_id' => $result->activityId,
            'state' => $result->state->value,
            'outcome' => $result->outcome->value,
            'invocations' => $result->invocations,
            'cues' => array_map(static fn(Cue $cue): array => $cue->toCanonical(), $cues),
        ];
    }

    private static function summary(\Phalanx\Agent\Activity\Result $result): string
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
