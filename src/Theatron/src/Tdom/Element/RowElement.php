<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style;

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
