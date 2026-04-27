<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Surface;

final class RenderError implements Message
{
    public function __construct(
        public private(set) string $regionName,
        public private(set) \Throwable $exception,
    ) {}
}
