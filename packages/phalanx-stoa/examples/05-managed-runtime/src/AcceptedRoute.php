<?php

declare(strict_types=1);

namespace Acme\StoaDemo\ManagedRuntime;

use GuzzleHttp\Psr7\Response;
use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class AcceptedRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): Response
    {
        return new Response(202, ['Content-Type' => 'text/plain'], 'queued');
    }
}
