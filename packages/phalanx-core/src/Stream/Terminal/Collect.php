<?php

declare(strict_types=1);

namespace Phalanx\Stream\Terminal;

use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Contract\StreamSource;

final readonly class Collect
{
    public function __construct(
        private StreamSource $source,
    ) {
    }

    /** @return array<mixed> */
    public function __invoke(StreamContext $ctx): array
    {
        return iterator_to_array(($this->source)($ctx));
    }
}
