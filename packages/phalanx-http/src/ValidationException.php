<?php

declare(strict_types=1);

namespace Phalanx\Http;

final class ValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly mixed $value,
        public readonly RequestValidator $validator,
    ) {
        parent::__construct("Validation failed for field '{$field}'");
    }
}
