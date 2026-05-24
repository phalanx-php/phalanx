<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;

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
