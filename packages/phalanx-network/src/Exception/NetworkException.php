<?php

declare(strict_types=1);

namespace Phalanx\Network\Exception;

class NetworkException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $ip = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
