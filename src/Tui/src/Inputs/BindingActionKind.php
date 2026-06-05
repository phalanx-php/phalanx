<?php

declare(strict_types=1);

namespace Phalanx\Tui\Inputs;

enum BindingActionKind
{
    case Quit;
    case Workspace;
    case Toggle;
    case Back;
    case Action;
}
