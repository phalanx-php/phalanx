<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;

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
