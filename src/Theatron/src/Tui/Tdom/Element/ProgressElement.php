<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Element;

use Phalanx\Theatron\Tui\Styles\Line;
use Phalanx\Theatron\Tui\Tdom\Element;
use Phalanx\Theatron\Tui\Tdom\ElementType;
use Phalanx\Theatron\Tui\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tui\Tdom\Style;

final class ProgressElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Progress; }

    public function __construct(
        private(set) float $value,
        private(set) string|Line|null $label = null,
        private(set) ?Style $style = null,
    ) {
    }
}
