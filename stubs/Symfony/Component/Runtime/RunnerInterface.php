<?php

declare(strict_types=1);

namespace Symfony\Component\Runtime;

interface RunnerInterface
{
    public function run(): int;
}
