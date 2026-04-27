<?php

declare(strict_types=1);

namespace Phalanx\Argos\Exception;

final class HostUnreachableException extends NetworkException
{
    public function __construct(string $ip, string $method = 'unknown')
    {
        parent::__construct("Host $ip unreachable via $method", $ip);
    }
}
