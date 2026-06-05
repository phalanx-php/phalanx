<?php

declare(strict_types=1);

namespace Phalanx\Config;

final readonly class ValidationContext
{
    public function __construct(
        public AppEnv $env = AppEnv::Local,
        public bool $strict = false,
        public ValidationPurpose $purpose = ValidationPurpose::Boot,
    ) {
    }
}
