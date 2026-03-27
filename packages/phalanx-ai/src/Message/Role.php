<?php

declare(strict_types=1);

namespace Phalanx\Ai\Message;

enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
