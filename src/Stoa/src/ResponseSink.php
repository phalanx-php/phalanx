<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Swoole\Http\Response;

final readonly class ResponseSink
{
    public function __construct(public Response $response)
    {
    }
}
