<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Transport\Sync;

/**
 * Thrown by {@see Transport::stream()} when the HTTP response status is not
 * in the 2xx range. Carries the status code and raw response body for
 * diagnostics and caller-side error handling.
 *
 * Final — sealed sentinel exception; subclassing would alter the error
 * taxonomy consumers depend on.
 */
final class HttpError extends \RuntimeException
{
    public function __construct(
        private(set) int $statusCode,
        private(set) string $responseBody,
        string $message,
    ) {
        parent::__construct($message);
    }
}
