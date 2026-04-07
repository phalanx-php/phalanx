<?php

declare(strict_types=1);

namespace Phalanx\Auth;

final class AuthorizationException extends \RuntimeException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct($message);
    }
}
