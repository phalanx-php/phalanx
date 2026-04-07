<?php

declare(strict_types=1);

namespace Phalanx\Http\Contract;

use Phalanx\Http\RequestScope;

/**
 * Business validator for an HTTP route.
 *
 * Validators are constructed via HandlerResolver (constructor injection from
 * the service container) and run before the handler executes. The input DTO
 * is passed after hydration -- validators operate on the typed, coerced value.
 * When a handler declares no input parameter, $input is null.
 *
 * Return an empty array to pass. Return a non-empty field => messages map to
 * fail -- the dispatcher throws ValidationException with the collected errors
 * from all validators before the handler runs.
 *
 * @return array<string, list<string>> field => error messages, empty = valid
 */
interface RouteValidator
{
    /**
     * @return array<string, list<string>> field => error messages, empty = valid
     */
    public function validate(object|null $input, RequestScope $scope): array;
}
