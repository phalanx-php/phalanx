<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Element;

use Phalanx\Theatron\Tui\Tdom\Element;
use Phalanx\Theatron\Tui\Tdom\ElementType;
use Phalanx\Theatron\Tui\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tui\Tdom\Renderable;
use Phalanx\Theatron\Tui\Tdom\Style;

final class ColumnElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Column; }

    /** @param list<Renderable> $children */
    public function __construct(
        private(set) array $children,
        private(set) ?Style $style = null,
    ) {
    }
}
