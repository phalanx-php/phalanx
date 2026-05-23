<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Contract;

/**
 * Declares business validators that must run before the handler executes.
 *
 * Each entry is a class-string of a RouteValidator. Validators are
 * constructed via HandlerResolver (constructor-injected from the service
 * container) and invoked in declaration order on every request before the
 * handler runs.
 *
 * Validators receive the hydrated input DTO (or null when the handler
 * declares no input parameter) and the RequestScope. A non-empty errors
 * array from any validator aborts dispatch with a ValidationException.
 */
interface HasValidators
{
    /** @var list<class-string<RouteValidator>> */
    public array $validators { get; }
}
