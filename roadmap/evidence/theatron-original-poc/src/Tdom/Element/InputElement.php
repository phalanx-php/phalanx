<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\Style;

final class InputElement implements Element
{
    public ElementType $type { get => ElementType::Input; }

    public function __construct(
        private(set) string $value = '',
        private(set) string $prompt = '> ',
        private(set) int $cursor = 0,
        private(set) ?Style $style = null,
    ) {
    }
}
