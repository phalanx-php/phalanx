<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use OpenSwoole\Http\Response;

final readonly class ResponseSink
{
    public function __construct(public Response $response)
    {
    }
}
