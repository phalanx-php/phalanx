<?php

declare(strict_types=1);

namespace Phalanx\Http;

/**
 * Declares HTTP headers a handler requires (or optionally accepts).
 *
 * Required headers cause dispatch to abort with a ValidationException
 * (status 422) if missing or pattern-mismatched. Optional headers are
 * advisory metadata for OpenAPI generation. The runner enforces this
 * contract on every request before the handler runs.
 */
interface RequiresHeaders
{
    /** @var list<Header> */
    public array $requiredHeaders { get; }
}
