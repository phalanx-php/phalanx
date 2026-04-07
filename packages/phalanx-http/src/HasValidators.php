<?php

declare(strict_types=1);

namespace Phalanx\Http;

/**
 * Declares business validators that must run before the handler executes.
 *
 * Each entry is a class-string of a RouteValidator. Validators are constructed
 * via HandlerResolver and invoked in declaration order. Throwing aborts dispatch
 * and the runner converts the exception to an HTTP response.
 */
interface HasValidators
{
    /** @var list<class-string<RouteValidator>> */
    public array $validators { get; }
}
