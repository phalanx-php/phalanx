<?php

declare(strict_types=1);

namespace Phalanx\Archon;

final class CommandInput
{
    public function __construct(
        public private(set) CommandArgs $args,
        public private(set) CommandOptions $options,
    ) {
    }
}
