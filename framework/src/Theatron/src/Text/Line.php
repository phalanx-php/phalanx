<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Text;

use Phalanx\Theatron\Style\Style;

final class Line
{
    /** @var list<Span> */
    private(set) array $spans;
    private(set) int $width;

    public function __construct(Span ...$spans)
    {
        $this->spans = array_values($spans);
        $this->width = self::sumWidths($this->spans);
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

    public function append(Span $span): self
    {
        $new = clone $this;
        $new->spans = [...$this->spans, $span];
        $new->width = $this->width + $span->width;

        return $new;
    }

    public function push(Span $span): void
    {
        $this->spans[] = $span;
        $this->width += $span->width;
    }

    /** @return list<self> */
    public function wrapToWidth(int $maxWidth): array
    {
        if ($maxWidth <= 0 || $this->width <= $maxWidth) {
            return [$this];
        }

        $segments = [];

        foreach ($this->spans as $span) {
            preg_match_all('/\S+|\s+/u', $span->content, $matches);

            foreach ($matches[0] as $part) {
                $segments[] = [$part, $span->style, trim($part) === ''];
            }
        }

        $lines = [];
        /** @var list<Span> $currentSpans */
        $currentSpans = [];
        $col = 0;

        foreach ($segments as [$text, $style, $isSpace]) {
            $width = mb_strwidth($text);

            if ($col === 0 && $isSpace) {
                continue;
            }

            if (!$isSpace && $col + $width > $maxWidth) {
                if ($col > 0) {
                    self::trimTrailingSpace($currentSpans);
                    $lines[] = new self(...$currentSpans);
                    $currentSpans = [];
                    $col = 0;
                }

                if ($width > $maxWidth) {
                    while (mb_strwidth($text) > $maxWidth) {
                        $chunk = mb_strimwidth($text, 0, $maxWidth);
                        $lines[] = new self(Span::styled($chunk, $style));
                        $text = mb_substr($text, mb_strlen($chunk));
                    }

                    if ($text !== '') {
                        $currentSpans[] = Span::styled($text, $style);
                        $col = mb_strwidth($text);
                    }

                    continue;
                }
            }

            $currentSpans[] = Span::styled($text, $style);
            $col += $width;
        }

        if ($currentSpans !== []) {
            $lines[] = new self(...$currentSpans);
        }

        return $lines === [] ? [new self()] : $lines;
    }

    /** @param list<Span> $spans */
    private static function sumWidths(array $spans): int
    {
        $total = 0;

        foreach ($spans as $span) {
            $total += $span->width;
        }

        return $total;
    }

    /** @param list<Span> $spans */
    private static function trimTrailingSpace(array &$spans): void
    {
        if ($spans === []) {
            return;
        }

        $lastIdx = count($spans) - 1;
        $last = $spans[$lastIdx];
        $trimmed = rtrim($last->content);

        if ($trimmed === '') {
            array_pop($spans);
        } elseif ($trimmed !== $last->content) {
            $spans[$lastIdx] = Span::styled($trimmed, $last->style);
        }
    }
}
