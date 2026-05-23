<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Element;

use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;

final class TextElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Text; }

    public function __construct(
        private(set) string|Line $content,
        private(set) ?Style $style = null,
    ) {
    }
}
