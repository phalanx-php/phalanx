<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\Style;

final class ScrollElement implements Element
{
    public ElementType $type { get => ElementType::Scroll; }

    public function __construct(
        private(set) string $content,
        private(set) ?int $maxLines = null,
        private(set) ?Style $style = null,
    ) {
    }
}
