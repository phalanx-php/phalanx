<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style;

final class PanelElement implements Element
{
    public ElementType $type { get => ElementType::Panel; }

    public function __construct(
        private(set) string $title,
        private(set) Renderable $child,
        private(set) ?Style $style = null,
    ) {
    }
}
