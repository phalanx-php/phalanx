<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response;

use Phalanx\Stoa\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Contract for mapping an exception to an HTTP response.
 *
 * This allows developers to catch specific domain exceptions and return
 * custom responses (e.g., Problem Details JSON, custom HTML pages) without
 * modifying the core StoaRunner.
 */
interface ErrorResponseRenderer
{
    /**
     * Renders a response for the given exception.
     *
     * @param RequestContext $ctx The HTTP request context.
     * @param Throwable $e The exception that occurred.
     * @return ResponseInterface|null The response, or null to delegate to the next renderer.
     */
    public function render(RequestContext $ctx, Throwable $e): ?ResponseInterface;
}
