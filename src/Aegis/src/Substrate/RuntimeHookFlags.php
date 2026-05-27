<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

final readonly class RuntimeHookFlags
{
    public function __construct(
        public int $tcp,
        public int $udp,
        public int $unix,
        public int $ssl,
        public int $tls,
        public int $file,
        public int $sleep,
        public int $curl,
        public int $blocking,
        public int $all,
    ) {}
}
