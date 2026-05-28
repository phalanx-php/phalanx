<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

enum InputMode: string
{
    case Normal = 'normal';
    case Insert = 'insert';
}
