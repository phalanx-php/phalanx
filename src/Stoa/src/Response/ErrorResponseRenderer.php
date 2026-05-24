<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response;

use Phalanx\Stoa\RequestScope;
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
     * @param RequestScope $scope The HTTP request scope.
     * @param Throwable $e The exception that occurred.
     * @return ResponseInterface|null The response, or null to delegate to the next renderer.
     */
    public function render(RequestScope $scope, Throwable $e): ?ResponseInterface;
}
