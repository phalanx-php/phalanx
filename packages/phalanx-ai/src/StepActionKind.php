<?php

declare(strict_types=1);

namespace Phalanx\Ai;

enum StepActionKind
{
    case Continue;
    case Finalize;
    case Inject;
}
