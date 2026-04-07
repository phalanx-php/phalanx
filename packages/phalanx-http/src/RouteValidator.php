<?php

declare(strict_types=1);

namespace Phalanx\Http;

/**
 * Business validator for an HTTP route.
 *
 * Implementations are constructed via HandlerResolver (constructor injection
 * from the service container) and run before the handler executes. Throwing
 * any exception aborts dispatch; the runner converts it to an HTTP response.
 */
interface RouteValidator
{
    public function validate(RequestScope $scope): void;
}
