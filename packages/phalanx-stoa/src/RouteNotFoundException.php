<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class RouteNotFoundException extends \RuntimeException implements ToResponse
{
    public int $status { get => 404; }

    public function __construct(
        public private(set) string $method,
        public private(set) string $path,
    ) {
        parent::__construct("No route matches {$method} {$path}");
    }

    public function toResponse(): ResponseInterface
    {
        return Response::json([
            'error' => 'Not Found',
            'message' => $this->getMessage(),
        ])->withStatus($this->status);
    }
}
