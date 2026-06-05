<?php

declare(strict_types=1);

namespace Phalanx\Http\Contract;

interface Validatable
{
    /** @return array<string, list<string>> field => error messages, empty = valid */
    public function validate(): array;
}
