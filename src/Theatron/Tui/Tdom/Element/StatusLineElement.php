<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Element;

use Phalanx\Theatron\Tui\Tdom\Element;
use Phalanx\Theatron\Tui\Tdom\ElementType;
use Phalanx\Theatron\Tui\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tui\Tdom\Renderable;
use Phalanx\Theatron\Tui\Tdom\Style;

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
