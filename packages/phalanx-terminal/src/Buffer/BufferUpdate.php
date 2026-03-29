<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Buffer;

use Phalanx\Terminal\Style\Style;

final class BufferUpdate
{
    public function __construct(
        public private(set) int $x,
        public private(set) int $y,
        public private(set) string $char,
        public private(set) Style $style,
    ) {}
}
