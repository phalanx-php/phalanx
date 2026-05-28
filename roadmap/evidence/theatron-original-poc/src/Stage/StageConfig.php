<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stage;

use Phalanx\Theatron\Style\ColorMode;

final class StageConfig
{
    public function __construct(
        private(set) ScreenMode $screenMode = ScreenMode::Alternate,
        private(set) bool $mouseTracking = false,
        private(set) bool $bracketedPaste = true,
        private(set) bool $syncOutput = false,
        private(set) bool $handleInput = false,
        private(set) bool $defaultExitHandler = true,
        private(set) ColorMode $colorMode = ColorMode::Ansi24,
        private(set) int $activeIntervalUs = 10_000,
        private(set) int $idleIntervalUs = 250_000,
        private(set) mixed $stream = null,
        private(set) ?string $captureFile = null,
        private(set) bool $fullSgr = false,
        private(set) bool $flushMemoryCaches = true,
    ) {
    }
}
