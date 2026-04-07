<?php

declare(strict_types=1);

namespace Phalanx\Http\Response;

use Phalanx\Http\ToResponse;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

class NoContent implements ToResponse
{
    public const int STATUS = 204;

    public int $status { get => static::STATUS; }

    public function toResponse(): ResponseInterface
    {
        return new Response($this->status);
    }
}
