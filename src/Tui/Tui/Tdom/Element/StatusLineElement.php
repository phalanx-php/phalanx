<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Tdom\Element;

use Phalanx\Tui\Tui\Tdom\Element;
use Phalanx\Tui\Tui\Tdom\ElementType;
use Phalanx\Tui\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tui\Tdom\Renderable;
use Phalanx\Tui\Tui\Tdom\Style;

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
