<?php

declare(strict_types=1);

namespace Convoy\Console;

final class CommandInput
{
    public function __construct(
        public private(set) CommandArgs $args,
        public private(set) CommandOptions $options,
    ) {
    }
}
