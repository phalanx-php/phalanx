<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Exception;

class SshException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        public readonly string $stderr = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
