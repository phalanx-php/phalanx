<?php

declare(strict_types=1);

namespace Phalanx\Eidolon\Middleware;

use Phalanx\Stoa\RequestCtxKey;

/** @implements RequestCtxKey<string> */
enum EnvelopeCtxKey implements RequestCtxKey
{
    case TraceId;

    public function key(): string
    {
        return 'eidolon.trace_id';
    }
}
