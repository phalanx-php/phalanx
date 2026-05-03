<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response;

use GuzzleHttp\Psr7\Response;
use Phalanx\Stoa\ToResponse;
use Psr\Http\Message\ResponseInterface;

class Created implements ToResponse
{
    public const int STATUS = 201;

    public int $status { get => static::STATUS; }

    public function __construct(
        public readonly mixed $data,
    ) {
    }

    public function toResponse(): ResponseInterface
    {
        return new Response(
            $this->status,
            ['Content-Type' => 'application/json'],
            json_encode($this->data, JSON_THROW_ON_ERROR),
        );
    }
}
