<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom\Element;

use Phalanx\Tui\Tdom\Element;
use Phalanx\Tui\Tdom\ElementType;
use Phalanx\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tdom\Renderable;
use Phalanx\Tui\Tdom\Style;

final class RowElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Row; }

    /** @param list<Renderable> $children */
    public function __construct(
        private(set) array $children,
        private(set) ?Style $style = null,
    ) {
    }
}
