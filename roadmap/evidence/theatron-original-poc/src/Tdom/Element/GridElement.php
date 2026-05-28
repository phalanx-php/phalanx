<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;

final class GridElement implements Element
{
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
