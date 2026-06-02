<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Element;

use Phalanx\Theatron\Tui\Tdom\Element;
use Phalanx\Theatron\Tui\Tdom\ElementType;
use Phalanx\Theatron\Tui\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tui\Tdom\Style;

final class DividerElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Divider; }

    public function __construct(
        private(set) ?Style $style = null,
    ) {
    }
}
