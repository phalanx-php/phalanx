<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Psr\Http\Message\ResponseInterface;

/**
 * Anything that can present itself as an HTTP response.
 *
 * Implementations expose their HTTP status as a property hook so the runner,
 * OpenAPI generator, and exception-as-response pipeline can read it without
 * materializing the full PSR-7 response. Domain exception classes implement
 * this interface so they can be caught and converted at the runner edge.
 */
interface ToResponse
{
    public int $status { get; }

    public function toResponse(): ResponseInterface;
}
