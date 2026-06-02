<?php

declare(strict_types=1);

namespace Phalanx\Themis;

interface ConfigSelector extends Config
{
    public function active(): Config;
}
