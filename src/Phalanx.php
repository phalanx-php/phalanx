<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Bootstrap\BootstrapContract;

final class Phalanx
{
    public const string VERSION = '2.0-dev';

    public static function bootstrapContract(): BootstrapContract
    {
        return BootstrapContract::current();
    }
}
