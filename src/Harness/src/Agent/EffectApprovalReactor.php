<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\Overlay\EffectApprovalOverlay;
use Phalanx\Harness\Ui\Slices\ActivityStatus;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Navigation\WorkspaceNavigator;

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
