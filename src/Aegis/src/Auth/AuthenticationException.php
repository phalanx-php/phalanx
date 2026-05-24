<?php

declare(strict_types=1);

namespace Phalanx\Auth;

final class AuthenticationException extends \RuntimeException
{
    public function __construct(string $message = 'Unauthenticated')
    {
        parent::__construct($message);
    }
}
