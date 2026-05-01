<?php

declare(strict_types=1);

namespace Phalanx\Cancellation;

use RuntimeException;
use Throwable;

/**
 * Thrown by any() when every task fails. Carries the per-task errors.
 */
final class AggregateException extends RuntimeException
{
    /** @param array<string|int, Throwable> $errors */
    public function __construct(public readonly array $errors)
    {
        $messages = array_map(
            static fn(Throwable $e, string|int $key): string => "[{$key}] {$e->getMessage()}",
            $errors,
            array_keys($errors),
        );
        parent::__construct('all tasks failed: ' . implode('; ', $messages));
    }
}
