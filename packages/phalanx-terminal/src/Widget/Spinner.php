<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;

final class Spinner implements Widget
{
    private int $frame = 0;

    private const array DOTS = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private const array LINE = ['-', '\\', '|', '/'];
    private const array BRAILLE = ['⣾', '⣽', '⣻', '⢿', '⡿', '⣟', '⣯', '⣷'];

    private Style $spinnerStyle;
    private Style $labelStyle;

    /** @var list<string> */
    private array $frames;

    /**
     * @param list<string>|null $frames
     */
    public function __construct(
        private string $label = '',
        ?array $frames = null,
        ?Style $spinnerStyle = null,
        ?Style $labelStyle = null,
    ) {
        $this->frames = $frames ?? self::DOTS;
        $this->spinnerStyle = $spinnerStyle ?? Style::new()->fg('cyan');
        $this->labelStyle = $labelStyle ?? Style::new();
    }

    public function tick(): void
    {
        $this->frame++;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->height === 0 || $area->width === 0) {
            return;
        }

        $idx = $this->frame % count($this->frames);
        $char = $this->frames[$idx];

        $buffer->putString($area->x, $area->y, $char, $this->spinnerStyle);

        if ($this->label !== '' && $area->width > 2) {
            $buffer->putString($area->x + 2, $area->y, $this->label, $this->labelStyle);
        }
    }
}
