<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Terminal;

use Phalanx\Terminal\Style\ColorMode;

final class TerminalConfig
{
    public function __construct(
        public private(set) int $width = 80,
        public private(set) int $height = 24,
        public private(set) ColorMode $colorMode = ColorMode::Ansi24,
        public private(set) bool $isTty = true,
    ) {}

    public function withSize(int $width, int $height): self
    {
        return new self($width, $height, $this->colorMode, $this->isTty);
    }

    public function withColorMode(ColorMode $mode): self
    {
        return new self($this->width, $this->height, $mode, $this->isTty);
    }
}
