<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Element;

use Phalanx\Theatron\Tui\Styles\Line;
use Phalanx\Theatron\Tui\Tdom\Element;
use Phalanx\Theatron\Tui\Tdom\ElementType;
use Phalanx\Theatron\Tui\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tui\Tdom\Renderable;
use Phalanx\Theatron\Tui\Tdom\Style;

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
