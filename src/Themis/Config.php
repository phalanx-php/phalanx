<?php

declare(strict_types=1);

namespace Phalanx\Themis;

interface Config
{
    public bool $configured { get; }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array;
}
