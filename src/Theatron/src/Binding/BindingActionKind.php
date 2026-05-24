<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Binding;

enum BindingActionKind
{
    case Quit;
    case Workspace;
    case Toggle;
    case Back;
    case Action;
}
