<?php

declare(strict_types=1);

namespace Phalanx\Config;

enum ValidationPurpose
{
    case Boot;
    case Doctor;
    case Example;
}
