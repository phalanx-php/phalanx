<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;

final class AccordionSection
{
    public function __construct(
        public private(set) string $title,
        public private(set) Widget $content,
        public bool $expanded = false,
        public private(set) int $contentHeight = 5,
    ) {}
}

final class Accordion implements Widget
{
    /** @var list<AccordionSection> */
    private array $sections;

    private Style $titleStyle;
    private Style $activeTitleStyle;

    /** @param list<AccordionSection> $sections */
    public function __construct(
        array $sections = [],
        ?Style $titleStyle = null,
        ?Style $activeTitleStyle = null,
    ) {
        $this->sections = $sections;
        $this->titleStyle = $titleStyle ?? Style::new()->bold();
        $this->activeTitleStyle = $activeTitleStyle ?? Style::new()->bold()->fg('cyan');
    }

    public function addSection(AccordionSection $section): void
    {
        $this->sections[] = $section;
    }

    public function toggle(int $index): void
    {
        if (isset($this->sections[$index])) {
            $this->sections[$index]->expanded = !$this->sections[$index]->expanded;
        }
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->height === 0 || $area->width === 0) {
            return;
        }

        $y = $area->y;

        foreach ($this->sections as $section) {
            if ($y >= $area->bottom) {
                break;
            }

            $indicator = $section->expanded ? '▼' : '▶';
            $style = $section->expanded ? $this->activeTitleStyle : $this->titleStyle;
            $titleText = " {$indicator} {$section->title}";

            $buffer->putString($area->x, $y, $titleText, $style);
            $y++;

            if ($section->expanded && $y < $area->bottom) {
                $contentHeight = min($section->contentHeight, $area->bottom - $y);
                $contentArea = Rect::of($area->x + 2, $y, $area->width - 2, $contentHeight);

                if ($contentArea->width > 0 && $contentArea->height > 0) {
                    $section->content->render($contentArea, $buffer);
                }

                $y += $contentHeight;
            }
        }
    }
}
