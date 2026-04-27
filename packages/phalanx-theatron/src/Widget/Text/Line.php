<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Widget\Text;

use Phalanx\Theatron\Style\Style;

final class Line
{
    /** @var list<Span> */
    public private(set) array $spans;

    public function __construct(Span ...$spans)
    {
        $this->spans = array_values($spans);
    }

    public static function plain(string $text): self
    {
        return new self(Span::plain($text));
    }

    public static function styled(string $text, Style $style): self
    {
        return new self(Span::styled($text, $style));
    }

    public static function from(Span ...$spans): self
    {
        return new self(...$spans);
    }

    public int $width {
        get {
            $total = 0;

            foreach ($this->spans as $span) {
                $total += $span->width;
            }

            return $total;
        }
    }

    public function append(Span $span): self
    {
        $new = clone $this;
        $new->spans = [...$this->spans, $span];

        return $new;
    }
}
