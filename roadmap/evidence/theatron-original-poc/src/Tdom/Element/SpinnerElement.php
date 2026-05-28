<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\Style;

final class SpinnerElement implements Element
{
    public ElementType $type { get => ElementType::Spinner; }

    public function __construct(
        private(set) ?string $label = null,
        private(set) int $frame = 0,
        private(set) ?Style $style = null,
    ) {
    }
}
