<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Surface;

final class RenderError implements Message
{
    public function __construct(
        private(set) string $regionName,
        private(set) \Throwable $exception,
    ) {}
}
