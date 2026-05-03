<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class ValidationException extends \RuntimeException implements ToResponse
{
    public int $status { get => 422; }

    /** @param array<string, list<string>> $errors field => error messages */
    public function __construct(
        public readonly array $errors,
    ) {
        $count = array_sum(array_map('count', $errors));
        parent::__construct("Validation failed ({$count} error(s))");
    }

    public static function single(string $field, string $message): static
    {
        return new static([$field => [$message]]);
    }

    /** @param array<string, list<string>> $errors */
    public static function fromErrors(array $errors): static
    {
        return new static($errors);
    }

    public function toResponse(): ResponseInterface
    {
        return new Response(
            $this->status,
            ['Content-Type' => 'application/json'],
            json_encode([
                'error' => 'Validation Failed',
                'errors' => $this->errors,
            ], JSON_THROW_ON_ERROR),
        );
    }
}
