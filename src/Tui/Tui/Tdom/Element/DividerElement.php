<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Tdom\Element;

use Phalanx\Tui\Tui\Tdom\Element;
use Phalanx\Tui\Tui\Tdom\ElementType;
use Phalanx\Tui\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tui\Tdom\Style;

final class DividerElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Divider; }

    public function __construct(
        private(set) ?Style $style = null,
    ) {
    }
}
