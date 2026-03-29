<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Surface;

use Phalanx\Terminal\Style\ColorMode;
use Phalanx\Terminal\Terminal\TerminalConfig;

final class SurfaceConfig
{
    public function __construct(
        public private(set) TerminalConfig $terminal,
        public private(set) ScreenMode $mode = ScreenMode::Alternate,
        public private(set) float $contentFps = 30.0,
        public private(set) float $structureFps = 10.0,
        public private(set) bool $mouseTracking = false,
        public private(set) bool $bracketedPaste = true,
    ) {}

    public function withMode(ScreenMode $mode): self
    {
        $clone = clone $this;
        $clone->mode = $mode;

        return $clone;
    }

    public function withTerminal(TerminalConfig $terminal): self
    {
        $clone = clone $this;
        $clone->terminal = $terminal;

        return $clone;
    }
}
