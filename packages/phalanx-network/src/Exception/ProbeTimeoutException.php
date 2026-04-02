<?php

declare(strict_types=1);

namespace Phalanx\Network\Exception;

final class ProbeTimeoutException extends NetworkException
{
    public function __construct(string $ip, float $timeoutSeconds)
    {
        parent::__construct("Probe to $ip timed out after {$timeoutSeconds}s", $ip);
    }
}
