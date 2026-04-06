<?php

declare(strict_types=1);

namespace Phalanx\Http\Contract;

final readonly class InputMeta
{
    /** @param class-string $inputClass */
    public function __construct(
        public string $inputClass,
        public string $paramName,
    ) {}
}
