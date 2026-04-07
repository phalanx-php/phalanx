<?php

declare(strict_types=1);

namespace Phalanx\Http;

/**
 * Declares HTTP headers a handler requires (or optionally accepts).
 *
 * Each entry is a Header descriptor. Required headers cause dispatch to abort
 * with a 400 response if missing or pattern-mismatched. Optional headers are
 * advisory metadata for OpenAPI generation.
 */
interface RequiresHeaders
{
    /** @var list<Header> */
    public array $requiredHeaders { get; }
}
