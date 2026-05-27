<?php

declare(strict_types=1);

namespace Phalanx\Eidolon\Middleware;

final class EnvelopeTraceId
{
    public function __construct(
        private(set) ?string $value = null,
    ) {
    }
}
