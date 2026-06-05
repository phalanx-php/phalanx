<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom\Element;

use Phalanx\Tui\Styles\Line;
use Phalanx\Tui\Tdom\Element;
use Phalanx\Tui\Tdom\ElementType;
use Phalanx\Tui\Tdom\HasFluentStyle;
use Phalanx\Tui\Tdom\Style;

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
