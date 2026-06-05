<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Tdom\Element;

use Phalanx\Tui\Tui\Tdom\Element;
use Phalanx\Tui\Tui\Tdom\ElementType;
use Phalanx\Tui\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tui\Tdom\Style;

final class ScrollElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Scroll; }

    public function __construct(
        private(set) string $content,
        private(set) ?int $maxLines = null,
        private(set) ?Style $style = null,
    ) {
    }
}
