<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response;

use Phalanx\Stoa\ToResponse;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

class Created implements ToResponse
{
    public const int STATUS = 201;

    public int $status { get => static::STATUS; }

    public function __construct(
        public readonly mixed $data,
    ) {}

    public function toResponse(): ResponseInterface
    {
        return Response::json($this->data)->withStatus($this->status);
    }
}
