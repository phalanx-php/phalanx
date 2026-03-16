<?php

declare(strict_types=1);

namespace Convoy\Stream\Terminal;

use Convoy\Stream\Contract\StreamContext;
use Convoy\Stream\Contract\StreamSource;

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
