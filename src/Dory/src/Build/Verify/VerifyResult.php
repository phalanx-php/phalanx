<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build\Verify;

final class VerifyResult
{
    public function __construct(
        private(set) string $checkName,
        private(set) bool $passed,
        private(set) string $message,
    ) {
    }
}
