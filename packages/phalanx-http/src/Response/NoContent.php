<?php

declare(strict_types=1);

namespace Phalanx\Http\Response;

use Phalanx\Http\ToResponse;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

readonly class NoContent implements ToResponse
{
    public function toResponse(): ResponseInterface
    {
        return new Response(204);
    }
}
