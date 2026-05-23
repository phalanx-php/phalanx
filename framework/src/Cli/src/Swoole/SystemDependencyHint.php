<?php

declare(strict_types=1);

namespace Phalanx\Cli\Swoole;

final class SystemDependencyHint
{
    public function __construct(
        private(set) Platform $platform,
        private(set) string $packageName,
        private(set) string $installCommand,
    ) {
    }
}
