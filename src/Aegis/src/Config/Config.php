<?php

declare(strict_types=1);

namespace Phalanx\Config;

interface Config
{
    public bool $configured { get; }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array;
}
