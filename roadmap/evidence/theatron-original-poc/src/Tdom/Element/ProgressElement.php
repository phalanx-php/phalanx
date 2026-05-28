<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\Style;

final class ProgressElement implements Element
{
    public ElementType $type { get => ElementType::Progress; }

    public function __construct(
        private(set) float $value,
        private(set) ?string $label = null,
        private(set) ?Style $style = null,
    ) {
    }
}
