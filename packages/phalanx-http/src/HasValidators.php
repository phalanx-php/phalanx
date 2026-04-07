<?php

declare(strict_types=1);

namespace Phalanx\Http;

/**
 * Declares business validators that must run before the handler executes.
 *
 * Each entry is a class-string of a RouteValidator. Validators are
 * constructed via HandlerResolver (constructor-injected from the service
 * container) and invoked in declaration order on every request before the
 * handler runs. Throwing aborts dispatch; the runner converts a thrown
 * ToResponse-implementing exception to its declared HTTP response.
 */
interface HasValidators
{
    /** @var list<class-string<RouteValidator>> */
    public array $validators { get; }
}
