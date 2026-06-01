<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Inputs;

enum BindingActionKind
{
    case Quit;
    case Workspace;
    case Toggle;
    case Back;
    case Action;
}
