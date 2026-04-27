<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class MethodNotAllowedException extends \RuntimeException implements ToResponse
{
    public int $status { get => 405; }

    /** @param list<string> $allowedMethods */
    public function __construct(
        public private(set) string $method,
        public private(set) string $path,
        public private(set) array $allowedMethods,
    ) {
        parent::__construct("Method {$method} not allowed for {$path}. Allowed: " . implode(', ', $allowedMethods));
    }

    public function toResponse(): ResponseInterface
    {
        return Response::json([
            'error' => 'Method Not Allowed',
            'message' => $this->getMessage(),
        ])->withStatus($this->status)
            ->withHeader('Allow', implode(', ', $this->allowedMethods));
    }
}
