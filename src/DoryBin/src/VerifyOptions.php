<?php

declare(strict_types=1);

namespace Phalanx\DoryBin;

final class VerifyOptions
{
    public function __construct(
        private(set) string $binaryPath,

        private(set) ?BuildProfile $profile = null,
    ) {
    }
}
