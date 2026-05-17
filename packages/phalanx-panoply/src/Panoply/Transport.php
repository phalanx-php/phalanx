<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

/**
 * Contract for objects that open a streaming wire-level connection to a
 * provider endpoint. The generator yields raw byte chunks as they arrive;
 * callers are responsible for framing and parsing. Implementations honor
 * the supplied {@see Runtime} for cancellation signalling and cleanup
 * registration.
 */
interface Transport
{
    /**
     * Open a streaming wire-level request. The generator yields raw chunks.
     *
     * @return \Generator<int, string>
     */
    public function stream(Transport\Request $request, Runtime $runtime): \Generator;
}
