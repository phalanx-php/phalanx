<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Inputs\InputMode;
use Phalanx\Theatron\Tui\Inputs\InputModeSlice;

interface HasWorkspaceInputModes
{
    public function inputModeForWorkspace(string $workspace): ?InputModeSlice;

    public function saveInputModeForWorkspace(string $workspace, InputMode $mode, ?string $focusTarget): void;
}
