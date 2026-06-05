<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Tdom\Element;

use Phalanx\Tui\Tui\Tdom\Element;
use Phalanx\Tui\Tui\Tdom\ElementType;
use Phalanx\Tui\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tui\Tdom\Renderable;
use Phalanx\Tui\Tui\Tdom\Style;

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
