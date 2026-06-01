<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Element;

use Phalanx\Theatron\Tui\Tdom\Element;
use Phalanx\Theatron\Tui\Tdom\ElementType;
use Phalanx\Theatron\Tui\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tui\Tdom\Style;

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
