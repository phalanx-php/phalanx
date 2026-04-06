<?php

declare(strict_types=1);

namespace Phalanx\Http;

class ValidationException extends \RuntimeException
{
    /** @param array<string, list<string>> $errors field => error messages */
    public function __construct(
        public readonly array $errors,
    ) {
        $count = array_sum(array_map('count', $errors));
        parent::__construct("Validation failed ({$count} error(s))");
    }

    public static function single(string $field, string $message): static
    {
        return new static([$field => [$message]]);
    }

    /** @param array<string, list<string>> $errors */
    public static function fromErrors(array $errors): static
    {
        return new static($errors);
    }
}
