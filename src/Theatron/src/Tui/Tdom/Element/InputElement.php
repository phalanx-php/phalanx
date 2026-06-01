<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Element;

use Phalanx\Theatron\Tui\Styles\Line;
use Phalanx\Theatron\Tui\Tdom\Element;
use Phalanx\Theatron\Tui\Tdom\ElementType;
use Phalanx\Theatron\Tui\Tdom\HasFluentStyle;
use Phalanx\Theatron\Tui\Tdom\Style;

final class InputElement implements Element
{
    use HasFluentStyle;

    public ElementType $type { get => ElementType::Input; }

    public function __construct(
        private(set) string $value = '',
        private(set) string|Line $prompt = '> ',
        private(set) int $cursor = 0,
        private(set) ?Style $style = null,
        private(set) ?int $selectionStart = null,
        private(set) ?int $selectionEnd = null,
    ) {
    }
}
