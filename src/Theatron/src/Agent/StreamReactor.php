<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Agent;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity\Cancelled as ActivityCancelled;
use Phalanx\Panoply\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use Phalanx\Panoply\Cue\Effect\Authorized as EffectAuthorized;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Failed as EffectFailed;
use Phalanx\Panoply\Cue\Effect\Paused as EffectPaused;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Hazard\Scorer\Rules\Scorer;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Slice\ActivitySlice;
use Phalanx\Theatron\Template\Slice\ActivityStatus;
use Phalanx\Theatron\Template\Slice\EffectStatus;
use Phalanx\Theatron\Template\Slice\PendingEffect;

final class StreamReactor
{
    /**
     * @param iterable<Cue> $cues
     */
    public static function consume(iterable $cues, AppStore $store): void
    {
        foreach ($cues as $cue) {
            self::dispatch($cue, $store);
        }
    }

    private static function dispatch(Cue $cue, AppStore $store): void
    {
        $store->conversation = $store->conversation->appendCue($cue);

        match (true) {
            $cue instanceof TokenStop => null,
            $cue instanceof TokenDelta => null,
            $cue instanceof EffectAuthorized => self::onEffectAuthorized($cue, $store),
            $cue instanceof EffectPaused => self::onEffectPaused($cue, $store),
            $cue instanceof EffectFailed => self::onEffectFailed($cue, $store),
            $cue instanceof EffectDenied => self::onEffectDenied($cue, $store),
            $cue instanceof EffectExecuted => self::onEffectExecuted($cue, $store),
            $cue instanceof EffectRequested => self::onEffectRequested($cue, $store),
            $cue instanceof ActivityStarted => self::onActivityStarted($store),
            $cue instanceof ActivityFailed => self::onActivityEnded($store, ActivityStatus::Failed),
            $cue instanceof ActivityCompleted => self::onActivityEnded($store, ActivityStatus::Completed),
            $cue instanceof ActivityCancelled => self::onActivityEnded($store, ActivityStatus::Cancelled),
            $cue instanceof UsageDelta => self::onUsageDelta($cue, $store),
            $cue instanceof FinalUsage => self::onFinalUsage($cue, $store),
            default => null,
        };
    }

    private static function onEffectRequested(EffectRequested $cue, AppStore $store): void
    {
        $hazard = new Scorer()->score(Effect::of(
            id: $cue->effectId,
            kind: $cue->kind,
            summary: $cue->summary,
            arguments: $cue->arguments,
            requiresApproval: $cue->requiresApproval,
        ));

        $hazardLevel = $hazard->rank();
        $effect = new PendingEffect(
            kind: $cue->kind->value,
            summary: $cue->summary,
            arguments: $cue->arguments,
            hazardLevel: $hazardLevel,
            activityId: $cue->activityId,
            effectId: $cue->effectId,
            agentId: $cue->agentId,
            invocationId: $cue->invocationId,
            hazard: $hazard->value,
        );

        $store->effects = $store->effects->appendRequested($effect);

        if (!$cue->requiresApproval) {
            return;
        }

        $store->activity = $store->activity->awaitingApproval($effect);
    }

    private static function onEffectAuthorized(EffectAuthorized $cue, AppStore $store): void
    {
        $store->effects = $store->effects->mark(
            $cue->effectId,
            EffectStatus::Approved,
            grantId: $cue->grantId,
        );
    }

    private static function onEffectPaused(EffectPaused $cue, AppStore $store): void
    {
        $store->effects = $store->effects->mark(
            $cue->effectId,
            EffectStatus::Paused,
            reasonCodes: [$cue->reason],
        );
    }

    private static function onEffectExecuted(EffectExecuted $cue, AppStore $store): void
    {
        $store->effects = $store->effects->mark(
            $cue->effectId,
            EffectStatus::Executed,
            durationMs: $cue->durationMs,
        );
        self::onEffectResolved($store);
    }

    private static function onEffectDenied(EffectDenied $cue, AppStore $store): void
    {
        $store->effects = $store->effects->mark(
            $cue->effectId,
            EffectStatus::Denied,
            reasonCodes: $cue->reasonCodes,
        );
        self::onEffectResolved($store);
    }

    private static function onEffectFailed(EffectFailed $cue, AppStore $store): void
    {
        $store->effects = $store->effects->mark(
            $cue->effectId,
            EffectStatus::Failed,
            reasonCodes: [$cue->reason],
            errorClass: $cue->errorClass,
        );
        self::onEffectResolved($store);
    }

    private static function onEffectResolved(AppStore $store): void
    {
        $store->activity = $store->activity->effectResolved();
    }

    private static function onActivityStarted(AppStore $store): void
    {
        $store->activity = new ActivitySlice(status: ActivityStatus::Running);
    }

    private static function onActivityEnded(AppStore $store, ActivityStatus $terminal): void
    {
        $store->activity = $store->activity->activityEnded($terminal);
    }

    private static function onUsageDelta(UsageDelta $cue, AppStore $store): void
    {
        $store->activity = $store->activity->updateUsage($cue->inputTokens, $cue->outputTokens);
    }

    private static function onFinalUsage(FinalUsage $cue, AppStore $store): void
    {
        $store->activity = $store->activity->withUsage($cue->inputTokens, $cue->outputTokens);

        if ($cue->invocationId !== null) {
            $store->requests = $store->requests->updateTokenCountByInvocationId(
                $cue->invocationId,
                $store->activity->totalTokens,
            );
        }
    }
}
