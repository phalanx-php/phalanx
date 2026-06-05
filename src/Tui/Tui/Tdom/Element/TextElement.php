<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Tdom\Element;

use Phalanx\Tui\Tui\Styles\Line;
use Phalanx\Tui\Tui\Tdom\Element;
use Phalanx\Tui\Tui\Tdom\ElementType;
use Phalanx\Tui\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tui\Tdom\Style;

final class TextElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Text; }

    public function __construct(
        private(set) string|Line $content,
        private(set) ?Style $style = null,
    ) {
    }
}
