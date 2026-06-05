<?php

declare(strict_types=1);

namespace Phalanx\Agent\Grant;

enum Scope: string
{
    case Once = 'once';
    case Session = 'session';
    case Always = 'always';
    case Dynamic = 'dynamic';
}
