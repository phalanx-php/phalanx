<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Styles;

use Phalanx\Tui\Tui\Styles\Style;

final class Span
{
    private(set) Style $style;
    private(set) int $width;

    public function __construct(
        private(set) string $content,
        ?Style $style = null,
    ) {
        $this->style = $style ?? Style::new();
        $this->width = mb_strwidth($this->content);
    }

    public static function plain(string $content): self
    {
        return new self($content);
    }

    public static function styled(string $content, Style $style): self
    {
        return new self($content, $style);
    }
}
