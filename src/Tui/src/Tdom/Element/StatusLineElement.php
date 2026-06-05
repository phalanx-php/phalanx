<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom\Element;

use Phalanx\Tui\Tdom\Element;
use Phalanx\Tui\Tdom\ElementType;
use Phalanx\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tdom\Renderable;
use Phalanx\Tui\Tdom\Style;

final class StatusLineElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::StatusLine; }

    /** @param list<Renderable> $sections */
    public function __construct(
        private(set) array $sections,
        private(set) ?Style $style = null,
    ) {
    }
}
