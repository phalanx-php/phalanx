<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

use Closure;
use OpenSwoole\Coroutine\Channel;

final class StoreMutation
{
    /**
     * @param class-string<Slice> $slice
     * @param Closure(Slice): Slice $update
     * @param Channel $reply
     */
    public function __construct(
        private(set) string $slice,
        private(set) Closure $update,
        private(set) Channel $reply,
    ) {
    }
}
