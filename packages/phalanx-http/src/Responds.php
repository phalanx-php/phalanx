<?php

declare(strict_types=1);

namespace Phalanx\Http;

/**
 * Declares the response body types a handler may produce, keyed by HTTP status.
 *
 * Each entry maps an HTTP status code to a body class-string. The map covers
 * both successful responses (e.g. 201 => User::class) and domain error responses
 * (e.g. 409 => UserConflictError::class). The OpenAPI generator and response
 * negotiation pipeline read this contract via reflection without instantiating
 * the handler.
 */
interface Responds
{
    /** @var array<int, class-string> */
    public array $responseTypes { get; }
}
