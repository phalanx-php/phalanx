<?php

declare(strict_types=1);

namespace Convoy\Stream\Terminal;

use Convoy\Stream\Contract\StreamContext;
use Convoy\Stream\Contract\StreamSource;

final readonly class First
{
    public function __construct(
        private StreamSource $source,
    ) {
    }

    public function __invoke(StreamContext $ctx): mixed
    {
        foreach (($this->source)($ctx) as $value) {
            return $value;
        }

        return null;
    }
}
