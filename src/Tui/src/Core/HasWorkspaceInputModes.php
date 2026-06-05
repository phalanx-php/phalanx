<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Inputs\InputMode;
use Phalanx\Tui\Inputs\InputModeSlice;

interface HasWorkspaceInputModes
{
    public function inputModeForWorkspace(string $workspace): ?InputModeSlice;

    public function saveInputModeForWorkspace(string $workspace, InputMode $mode, ?string $focusTarget): void;
}
