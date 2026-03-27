<?php

declare(strict_types=1);

namespace Phalanx\Stream\Terminal;

use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Contract\StreamSource;

final readonly class Drain
{
    public function __construct(
        private StreamSource $source,
    ) {
    }

    public function __invoke(StreamContext $ctx): null
    {
        foreach (($this->source)($ctx) as $_) {
            $ctx->throwIfCancelled();
        }

        return null;
    }
}
