<?php

declare(strict_types=1);

namespace Phalanx\Http;

interface RequestValidator
{
    public function __invoke(mixed $value): bool;
}
