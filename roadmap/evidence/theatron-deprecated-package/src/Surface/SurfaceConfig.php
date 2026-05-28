<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Surface;

use Phalanx\Theatron\Style\ColorMode;
use Phalanx\Theatron\Terminal\TerminalConfig;

final class SurfaceConfig
{
    public function __construct(
        private(set) TerminalConfig $terminal,
        private(set) ScreenMode $mode = ScreenMode::Alternate,
        private(set) float $contentFps = 30.0,
        private(set) float $structureFps = 10.0,
        private(set) bool $mouseTracking = false,
        private(set) bool $bracketedPaste = true,
    ) {}

    public function withMode(ScreenMode $mode): self
    {
        return new self(
            $this->terminal,
            $mode,
            $this->contentFps,
            $this->structureFps,
            $this->mouseTracking,
            $this->bracketedPaste,
        );
    }

    public function withTerminal(TerminalConfig $terminal): self
    {
        return new self(
            $terminal,
            $this->mode,
            $this->contentFps,
            $this->structureFps,
            $this->mouseTracking,
            $this->bracketedPaste,
        );
    }
}
