<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tdom\Style;

final class DividerElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Divider; }

    public function __construct(
        private(set) ?Style $style = null,
    ) {
    }
}
