<?php

declare(strict_types=1);

namespace Phalanx\Athena;

enum StepActionKind
{
    case Continue;
    case Finalize;
    case Inject;
}
