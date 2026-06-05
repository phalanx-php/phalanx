<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tool;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Param
{
    public function __construct(
        private(set) string $description,
        private(set) bool $required = true,
        private(set) mixed $default = null,
    ) {
    }
}
