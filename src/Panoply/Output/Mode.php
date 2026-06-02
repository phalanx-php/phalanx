<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Output;

enum Mode: string
{
    case Text = 'text';
    case Artifact = 'artifact';
    case Structured = 'structured';
}
