<?php

declare(strict_types=1);

namespace Phalanx\Http;

/**
 * Declares the response body types a handler may produce, keyed by HTTP status.
 *
 * Each entry maps an HTTP status code to a body class-string. The map covers
 * both successful responses (e.g. 201 => User::class) and domain error responses
 * (e.g. 409 => UserConflictError::class).
 *
 * v0.6.0 status: this interface is currently DECLARATIVE METADATA. The
 * Phalanx dispatcher does not consume it at runtime, and the OpenAPI
 * generator does not yet read it. It is intended for OpenAPI integration
 * in a follow-up release. Implement it today to document handler contracts;
 * the framework will pick it up automatically when the consumer ships.
 */
interface Responds
{
    /** @var array<int, class-string> */
    public array $responseTypes { get; }
}
