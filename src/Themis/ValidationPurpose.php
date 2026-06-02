<?php

declare(strict_types=1);

namespace Phalanx\Themis;

enum ValidationPurpose
{
    case Boot;
    case Doctor;
    case Example;
}
