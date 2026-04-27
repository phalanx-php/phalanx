<?php

declare(strict_types=1);

namespace Phalanx\Auth;

interface Identity
{
    public string|int $id { get; }
}
