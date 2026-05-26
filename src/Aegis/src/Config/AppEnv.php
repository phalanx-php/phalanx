<?php

declare(strict_types=1);

namespace Phalanx\Config;

enum AppEnv: string
{
    case Local = 'local';
    case Test = 'test';
    case Stag = 'stag';
    case Prod = 'prod';
}
