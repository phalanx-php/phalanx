<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputModeSlice;

interface HasWorkspaceInputModes
{
    public function inputModeForWorkspace(string $workspace): ?InputModeSlice;

    public function saveInputModeForWorkspace(string $workspace, InputMode $mode, ?string $focusTarget): void;
}
