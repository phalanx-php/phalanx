<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui;

use Phalanx\Boot\AppContext;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Harness\Agent\AgentExecutorContract;
use Phalanx\Harness\Agent\AgentRuntime;
use Phalanx\Harness\Agent\EffectApprovalReactor;
use Phalanx\Harness\Ui\Slices\ActivitySlice;
use Phalanx\Harness\Ui\Slices\AgentRegistrySlice;
use Phalanx\Harness\Ui\Slices\ConversationSlice;
use Phalanx\Harness\Ui\Slices\DevToolsSlice;
use Phalanx\Harness\Ui\Slices\EffectLogSlice;
use Phalanx\Harness\Ui\Slices\InputSlice;
use Phalanx\Harness\Ui\Slices\LlmRequestSlice;
use Phalanx\Harness\Ui\Slices\RuntimeStatusSlice;
use Phalanx\Harness\Ui\Slices\SettingsSlice;
use Phalanx\Harness\Ui\Slices\WorkspaceViewSlice;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Contract\HasActivityPulse;
use Phalanx\Theatron\Contract\HasKeySequenceState;
use Phalanx\Theatron\Contract\HasRuntimeContext;
use Phalanx\Theatron\Contract\HasWorkspaceInputModes;
use Phalanx\Theatron\Contract\PreparesWorkspaceDraw;
use Phalanx\Theatron\Contract\ProvidesMountServices;
use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputModeSlice;
use Phalanx\Theatron\Input\KeySequenceState;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\State\Store;

class AppStore extends Store implements
    HasActivityPulse,
    HasKeySequenceState,
    HasRuntimeContext,
    HasWorkspaceInputModes,
    PreparesWorkspaceDraw,
    ProvidesMountServices
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

    public function activityIsBusy(): bool
    {
        return $this->activity->isBusy();
    }

    public function inputModeForWorkspace(string $workspace): ?InputModeSlice
    {
        return $this->workspaceView->inputModeFor($workspace);
    }

    public function keySequenceState(): KeySequenceState
    {
        return $this->keySequence;
    }

    public function prepareWorkspaceDraw(Navigator $navigator): void
    {
        EffectApprovalReactor::check($this, $navigator);
    }

    public function provideMountServices(MountSystem $mountSystem, ExecutionScope $scope): void
    {
        try {
            $executor = $scope->service(AgentExecutorContract::class);
            $mountSystem->provide(AgentRuntime::class, new AgentRuntime($this, $executor));
        } catch (ServiceNotFoundException) {
        }
    }

    public function receiveRuntimeContext(AppContext $context): void
    {
        $this->runtimeStatus = RuntimeStatusSlice::fromContext($context);
    }

    public function saveInputModeForWorkspace(string $workspace, InputMode $mode, ?string $focusTarget): void
    {
        $this->workspaceView = $this->workspaceView->withInputMode($workspace, $mode, $focusTarget);
    }

    public function tickActivity(): void
    {
        $this->activity = $this->activity->tick();
    }

    public function updateKeySequence(KeySequenceState $state): void
    {
        $this->keySequence = $state;
    }
}
