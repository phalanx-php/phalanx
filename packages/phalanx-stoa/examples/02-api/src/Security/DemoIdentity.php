<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Api\Security;

use Phalanx\Auth\Identity;

final class DemoIdentity implements Identity
{
    public string|int $id {
        get => $this->identityId;
    }

    public function __construct(private string|int $identityId)
    {
    }
}
