<?php

declare(strict_types=1);

namespace Phalanx\Http\Response;

use Phalanx\Http\ToResponse;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

readonly class Accepted implements ToResponse
{
    public function __construct(
        public mixed $data,
    ) {}

    public function toResponse(): ResponseInterface
    {
        return Response::json($this->data)->withStatus(202);
    }
}
