<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class RouteNotFoundException extends \RuntimeException implements ToResponse
{
    public int $status { get => 404; }

    public function __construct(
        private(set) string $method,
        private(set) string $path,
    ) {
        parent::__construct("No route matches {$method} {$path}");
    }

    public function toResponse(): ResponseInterface
    {
        return new Response(
            $this->status,
            ['Content-Type' => 'application/json'],
            json_encode([
                'error' => 'Not Found',
                'message' => $this->getMessage(),
            ], JSON_THROW_ON_ERROR),
        );
    }
}
