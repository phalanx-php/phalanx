<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use RuntimeException;
use Throwable;

final class SurrealException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function fromErrorEnvelope(mixed $error): self
    {
        if (!is_array($error)) {
            return new self('Surreal RPC request failed.');
        }

        $message = isset($error['message']) && is_string($error['message'])
            ? $error['message']
            : 'Surreal RPC request failed.';
        $code = isset($error['code']) && is_int($error['code']) ? $error['code'] : 0;

        return new self($message, $code);
    }
}
