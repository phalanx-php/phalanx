<?php

declare(strict_types=1);

namespace Phalanx\Config;

interface ConfigSelector extends Config
{
    public function active(): Config;
}
