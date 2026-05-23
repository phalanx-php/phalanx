<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Agent;

use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Navigation\WorkspaceNavigator;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Overlay\EffectApprovalOverlay;
use Phalanx\Theatron\Template\Slice\ActivityStatus;

final class EffectApprovalReactor
{
    public static function check(AppStore $store, Navigator $navigator): void
    {
        $activity = $store->activity;

        if ($activity->status !== ActivityStatus::AwaitingApproval) {
            return;
        }

        if ($activity->pendingEffect === null) {
            return;
        }

        if ($navigator instanceof WorkspaceNavigator) {
            foreach ($navigator->overlays() as $overlay) {
                if (
                    $overlay->component instanceof EffectApprovalOverlay
                    && $overlay->component->effect->effectId === $activity->pendingEffect->effectId
                ) {
                    return;
                }
            }
        }

        $navigator->overlay(
            EffectApprovalOverlay::class,
            effect: $activity->pendingEffect,
        );
    }
}
