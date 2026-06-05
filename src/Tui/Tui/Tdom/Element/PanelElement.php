<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Tdom\Element;

use Phalanx\Tui\Tui\Styles\Line;
use Phalanx\Tui\Tui\Tdom\Element;
use Phalanx\Tui\Tui\Tdom\ElementType;
use Phalanx\Tui\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tui\Tdom\Renderable;
use Phalanx\Tui\Tui\Tdom\Style;

final class PanelElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Panel; }

    public function __construct(
        private(set) string|Line $title,
        private(set) Renderable $child,
        private(set) ?Style $style = null,
    ) {
    }
}
