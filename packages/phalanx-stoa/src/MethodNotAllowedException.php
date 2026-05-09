<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class MethodNotAllowedException extends \RuntimeException implements ToResponse
{
    public int $status { get => 405; }

    /** @param list<string> $allowedMethods */
    public function __construct(
        private(set) string $method,
        private(set) string $path,
        private(set) array $allowedMethods,
    ) {
        parent::__construct("Method {$method} not allowed for {$path}. Allowed: " . implode(', ', $allowedMethods));
    }

    public function toResponse(): ResponseInterface
    {
        return new Response(
            $this->status,
            [
                'Allow' => implode(', ', $this->allowedMethods),
                'Content-Type' => 'application/json',
            ],
            json_encode([
                'error' => 'Method Not Allowed',
                'message' => $this->getMessage(),
            ], JSON_THROW_ON_ERROR),
        );
    }
}
