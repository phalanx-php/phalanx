<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Output;

enum Mode: string
{
    case Text = 'text';
    case Artifact = 'artifact';
    case Structured = 'structured';
}
