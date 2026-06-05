<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\State;

use Phalanx\Tui\Tui\Reactive\Store;

final class AgentHarnessStore extends Store
{
    public MessageTimelineSlice $messages {
        get => $this->read(MessageTimelineSlice::class);
        set {
            $this->write(MessageTimelineSlice::class, $value);
        }
    }

    public WorkPlanSlice $workPlan {
        get => $this->read(WorkPlanSlice::class);
        set {
            $this->write(WorkPlanSlice::class, $value);
        }
    }

    public LoopSlice $loop {
        get => $this->read(LoopSlice::class);
        set {
            $this->write(LoopSlice::class, $value);
        }
    }

    public EffectSlice $effects {
        get => $this->read(EffectSlice::class);
        set {
            $this->write(EffectSlice::class, $value);
        }
    }

    public ReviewSlice $reviews {
        get => $this->read(ReviewSlice::class);
        set {
            $this->write(ReviewSlice::class, $value);
        }
    }

    public ParticipantSlice $participants {
        get => $this->read(ParticipantSlice::class);
        set {
            $this->write(ParticipantSlice::class, $value);
        }
    }

    public ContextSlice $context {
        get => $this->read(ContextSlice::class);
        set {
            $this->write(ContextSlice::class, $value);
        }
    }

    public RuntimeSlice $runtime {
        get => $this->read(RuntimeSlice::class);
        set {
            $this->write(RuntimeSlice::class, $value);
        }
    }

    public InputComposerSlice $inputComposer {
        get => $this->read(InputComposerSlice::class);
        set {
            $this->write(InputComposerSlice::class, $value);
        }
    }

    public WorkspaceViewSlice $workspaceView {
        get => $this->read(WorkspaceViewSlice::class);
        set {
            $this->write(WorkspaceViewSlice::class, $value);
        }
    }

    public NotificationSlice $notifications {
        get => $this->read(NotificationSlice::class);
        set {
            $this->write(NotificationSlice::class, $value);
        }
    }

    public DevToolsSlice $devTools {
        get => $this->read(DevToolsSlice::class);
        set {
            $this->write(DevToolsSlice::class, $value);
        }
    }

    public function __construct()
    {
        $this->register(MessageTimelineSlice::class, new MessageTimelineSlice());
        $this->register(WorkPlanSlice::class, WorkPlanSlice::empty());
        $this->register(LoopSlice::class, new LoopSlice());
        $this->register(EffectSlice::class, new EffectSlice());
        $this->register(ReviewSlice::class, new ReviewSlice());
        $this->register(ParticipantSlice::class, new ParticipantSlice());
        $this->register(ContextSlice::class, new ContextSlice());
        $this->register(RuntimeSlice::class, new RuntimeSlice());
        $this->register(InputComposerSlice::class, new InputComposerSlice());
        $this->register(WorkspaceViewSlice::class, new WorkspaceViewSlice());
        $this->register(NotificationSlice::class, new NotificationSlice());
        $this->register(DevToolsSlice::class, new DevToolsSlice());
    }
}
