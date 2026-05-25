<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template;

use Phalanx\Theatron\Input\InputModeSlice;
use Phalanx\Theatron\Input\KeySequenceState;
use Phalanx\Theatron\State\Store;
use Phalanx\Theatron\Template\Slice\ActivitySlice;
use Phalanx\Theatron\Template\Slice\AgentRegistrySlice;
use Phalanx\Theatron\Template\Slice\ConversationSlice;
use Phalanx\Theatron\Template\Slice\DevToolsSlice;
use Phalanx\Theatron\Template\Slice\EffectLogSlice;
use Phalanx\Theatron\Template\Slice\InputSlice;
use Phalanx\Theatron\Template\Slice\LlmRequestSlice;
use Phalanx\Theatron\Template\Slice\RuntimeStatusSlice;
use Phalanx\Theatron\Template\Slice\SettingsSlice;
use Phalanx\Theatron\Template\Slice\WorkspaceViewSlice;

class AppStore extends Store
{
    public ConversationSlice $conversation {
        get => $this->read(ConversationSlice::class);
        set {
            $this->write(ConversationSlice::class, $value);
        }
    }

    public AgentRegistrySlice $agents {
        get => $this->read(AgentRegistrySlice::class);
        set {
            $this->write(AgentRegistrySlice::class, $value);
        }
    }

    public ActivitySlice $activity {
        get => $this->read(ActivitySlice::class);
        set {
            $this->write(ActivitySlice::class, $value);
        }
    }

    public InputSlice $input {
        get => $this->read(InputSlice::class);
        set {
            $this->write(InputSlice::class, $value);
        }
    }

    public EffectLogSlice $effects {
        get => $this->read(EffectLogSlice::class);
        set {
            $this->write(EffectLogSlice::class, $value);
        }
    }

    public LlmRequestSlice $requests {
        get => $this->read(LlmRequestSlice::class);
        set {
            $this->write(LlmRequestSlice::class, $value);
        }
    }

    public DevToolsSlice $devtools {
        get => $this->read(DevToolsSlice::class);
        set {
            $this->write(DevToolsSlice::class, $value);
        }
    }

    public SettingsSlice $settings {
        get => $this->read(SettingsSlice::class);
        set {
            $this->write(SettingsSlice::class, $value);
        }
    }

    public InputModeSlice $inputMode {
        get => $this->read(InputModeSlice::class);
        set {
            $this->write(InputModeSlice::class, $value);
        }
    }

    public RuntimeStatusSlice $runtimeStatus {
        get => $this->read(RuntimeStatusSlice::class);
        set {
            $this->write(RuntimeStatusSlice::class, $value);
        }
    }

    public WorkspaceViewSlice $workspaceView {
        get => $this->read(WorkspaceViewSlice::class);
        set {
            $this->write(WorkspaceViewSlice::class, $value);
        }
    }

    public KeySequenceState $keySequence {
        get => $this->read(KeySequenceState::class);
        set {
            $this->write(KeySequenceState::class, $value);
        }
    }

    public function __construct()
    {
        $this->register(ConversationSlice::class, new ConversationSlice());
        $this->register(AgentRegistrySlice::class, new AgentRegistrySlice());
        $this->register(ActivitySlice::class, new ActivitySlice());
        $this->register(InputSlice::class, new InputSlice());
        $this->register(EffectLogSlice::class, new EffectLogSlice());
        $this->register(LlmRequestSlice::class, new LlmRequestSlice());
        $this->register(DevToolsSlice::class, new DevToolsSlice());
        $this->register(SettingsSlice::class, new SettingsSlice());
        $this->register(InputModeSlice::class, new InputModeSlice());
        $this->register(RuntimeStatusSlice::class, new RuntimeStatusSlice());
        $this->register(WorkspaceViewSlice::class, new WorkspaceViewSlice());
        $this->register(KeySequenceState::class, new KeySequenceState());
    }
}
