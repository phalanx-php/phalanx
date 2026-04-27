<?php

declare(strict_types=1);

namespace Phalanx\Exception;

use Throwable;

class CompositeException extends \RuntimeException
{
    /**
     * @param list<Throwable> $errors
     * @param array<string|int, mixed> $results
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $results = [],
        string $message = 'Multiple operations failed',
    ) {
        $count = count($errors);
        parent::__construct("$message ($count failures)");
    }

    public function firstError(): ?Throwable
    {
        return $this->errors[0] ?? null;
    }
}
