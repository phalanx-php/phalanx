<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;

final class SpinnerElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Spinner; }

    public function __construct(
        private(set) string|Line|null $label = null,
        private(set) int $frame = 0,
        private(set) ?Style $style = null,
    ) {
    }
}
