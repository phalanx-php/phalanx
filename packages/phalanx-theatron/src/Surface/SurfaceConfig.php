<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Surface;

use Phalanx\Theatron\Style\ColorMode;
use Phalanx\Theatron\Terminal\TerminalConfig;

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
