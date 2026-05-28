<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Widget\Text;

use Phalanx\Theatron\Style\Style;

final class Span
{
    private(set) Style $style;

    public int $width {
        get => mb_strlen($this->content);
    }

    public function __construct(
        private(set) string $content,
        ?Style $style = null,
    ) {
        $this->style = $style ?? Style::new();
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
