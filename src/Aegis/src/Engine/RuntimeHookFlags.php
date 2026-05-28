<?php

declare(strict_types=1);

namespace Phalanx\Engine;

final class RuntimeHookFlags
{
    public function __construct(
        private(set) int $tcp,
        private(set) int $udp,
        private(set) int $unix,
        private(set) int $ssl,
        private(set) int $tls,
        private(set) int $file,
        private(set) int $sleep,
        private(set) int $curl,
        private(set) int $blocking,
        private(set) int $all,
    ) {
    }
}
