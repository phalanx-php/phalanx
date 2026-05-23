<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Agent;

use Phalanx\Athena\Activity\Activity;
use Phalanx\Athena\Activity\Config;
use Phalanx\Athena\Activity\Result;
use Phalanx\Athena\Grant\Scope as GrantScope;
use Phalanx\Athena\Grant\Store as GrantStore;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity\Cancelled as ActivityCancelled;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Id;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Template\Slice\PendingEffect;

final class AgentExecutor implements AgentExecutorContract
{
    public function __construct(
        private(set) Activity $activity,
        private(set) TaskScope $scope,
        private(set) Agent $agent,
        private(set) Config $config,
        private ?GrantStore $grantStore = null,
    ) {
    }

    /** @return iterable<Cue> */
    public function send(string $message): iterable
    {
        $log = Log::from([
            new \Phalanx\Panoply\Conversation\Record\Message(
                id: 'msg_' . \Phalanx\Panoply\Id::generate(),
                sequence: null,
                at: new \DateTimeImmutable(),
                role: 'user',
                text: $message,
            ),
        ]);

        $result = ($this->activity)($this->scope, $this->agent, $this->nextConfig(), $log);

        return $this->record($result);
    }

    /** @return iterable<Cue> */
    public function approve(PendingEffect $effect): iterable
    {
        $this->rememberGrant($effect);

        return [];
    }

    /** @return iterable<Cue> */
    public function deny(PendingEffect $effect): iterable
    {
        $at = new \DateTimeImmutable();

        return [
            new EffectDenied(
                id: 'cue_' . Id::generate(),
                sequence: 0,
                activityId: $effect->activityId,
                invocationId: $effect->invocationId,
                agentId: $effect->agentId,
                at: $at,
                effectId: $effect->effectId,
                reasonCodes: ['user-denied'],
            ),
            new ActivityCancelled(
                id: 'cue_' . Id::generate(),
                sequence: 1,
                activityId: $effect->activityId,
                invocationId: $effect->invocationId,
                agentId: $effect->agentId,
                at: $at,
                reason: 'Effect denied by user.',
            ),
        ];
    }

    public function cancel(): void
    {
        $this->scope->throwIfCancelled();
    }

    private function rememberGrant(PendingEffect $effect): void
    {
        if ($this->grantStore === null) {
            return;
        }

        $kind = Kind::tryFrom($effect->kind) ?? Kind::Custom;
        $hazard = Hazard::tryFrom($effect->hazard) ?? Hazard::High;

        $this->grantStore->remember(
            $this->scope,
            new Grant(
                id: 'grant_' . Id::generate(),
                subject: $effect->agentId ?? $this->agent->id,
                allowedEffects: [$kind],
                scope: GrantScope::Once->value,
                hazardCeiling: $hazard,
            ),
        );
    }

    private function nextConfig(): Config
    {
        return new Config(
            id: 'activity_' . Id::generate(),
            context: $this->config->context,
            maxInvocations: $this->config->maxInvocations,
            timeoutSeconds: $this->config->timeoutSeconds,
            hooks: $this->config->hooks,
        );
    }

    /**
     * @return iterable<Cue>
     */
    private function record(Result $result): iterable
    {
        foreach ($result->stream as $cue) {
            yield $cue;
        }
    }
}
