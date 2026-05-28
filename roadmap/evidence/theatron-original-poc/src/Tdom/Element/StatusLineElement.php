<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style;

final class StatusLineElement implements Element
{
    public ElementType $type { get => ElementType::StatusLine; }

    /** @param list<Renderable> $sections */
    public function __construct(
        private(set) array $sections,
        private(set) ?Style $style = null,
    ) {
    }
}
