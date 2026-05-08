<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;
use Psr\Http\Message\ResponseInterface;

final class HelloHandler implements Scopeable
{
    public function __invoke(Scope $scope): ResponseInterface
    {
        return new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/plain'],
            'hello',
        );
    }
}
