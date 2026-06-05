<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom\Element;

use Phalanx\Tui\Styles\Line;
use Phalanx\Tui\Tdom\Element;
use Phalanx\Tui\Tdom\ElementType;
use Phalanx\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tdom\Style;

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
