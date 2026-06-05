<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom\Element;

use Phalanx\Tui\Styles\Size;
use Phalanx\Tui\Tdom\Element;
use Phalanx\Tui\Tdom\ElementType;
use Phalanx\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tdom\Renderable;
use Phalanx\Tui\Tdom\Style;

final class GridElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Grid; }

    /**
     * @param list<Size> $columns
     * @param list<Renderable> $children
     */
    public function __construct(
        private(set) array $columns,
        private(set) array $children,
        private(set) ?Style $style = null,
    ) {
    }
}
