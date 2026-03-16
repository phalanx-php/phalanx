<?php

declare(strict_types=1);

namespace Convoy\Stream\Contract;

use Generator;

interface StreamSource
{
    public function __invoke(StreamContext $context): Generator;
}
