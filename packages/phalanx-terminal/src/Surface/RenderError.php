<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Surface;

final class RenderError implements Message
{
    public function __construct(
        public private(set) string $regionName,
        public private(set) \Throwable $exception,
    ) {}
}
