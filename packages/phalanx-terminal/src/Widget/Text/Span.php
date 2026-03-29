<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget\Text;

use Phalanx\Terminal\Style\Style;

final class Span
{
    public function __construct(
        public private(set) string $content,
        ?Style $style = null,
    ) {
        $this->style = $style ?? Style::new();
    }

    public private(set) Style $style;

    public static function plain(string $content): self
    {
        return new self($content);
    }

    public static function styled(string $content, Style $style): self
    {
        return new self($content, $style);
    }

    public int $width {
        get => mb_strlen($this->content);
    }
}
