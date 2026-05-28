<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Highlight;

use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;
use Tempest\Highlight\Highlighter as TempestEngine;

final class TempestHighlighter implements Highlighter
{
    private TempestEngine $engine;

    public function __construct(
        private(set) string $language = 'php',
    ) {
        $this->engine = new TempestEngine(new DarkTerminalTheme());
    }

    /** @return list<Line> */
    public function highlight(string $code): array
    {
        if ($this->language === 'php') {
            return (new PhpHighlighter())->highlight($code);
        }

        $highlighted = $this->engine->parse($code, $this->language);

        return self::parseAnsiLines($highlighted);
    }

    /** @return list<Line> */
    private static function parseAnsiLines(string $ansi): array
    {
        $rawLines = explode("\n", $ansi);
        $lines = [];

        foreach ($rawLines as $rawLine) {
            $lines[] = self::parseAnsiLine($rawLine);
        }

        return $lines ?: [Line::plain('')];
    }

    private static function parseAnsiLine(string $raw): Line
    {
        $spans = [];
        $offset = 0;
        $len = strlen($raw);
        $currentStyle = Style::new();

        while ($offset < $len) {
            $escPos = strpos($raw, "\033[", $offset);

            if ($escPos === false) {
                $text = substr($raw, $offset);

                if ($text !== '') {
                    $spans[] = Span::styled($text, $currentStyle);
                }

                break;
            }

            if ($escPos > $offset) {
                $text = substr($raw, $offset, $escPos - $offset);
                $spans[] = Span::styled($text, $currentStyle);
            }

            $mEnd = strpos($raw, 'm', $escPos + 2);

            if ($mEnd === false) {
                $spans[] = Span::plain(substr($raw, $escPos));
                break;
            }

            $code = substr($raw, $escPos + 2, $mEnd - $escPos - 2);
            $currentStyle = self::applySgrCode($currentStyle, $code);
            $offset = $mEnd + 1;
        }

        return $spans !== [] ? Line::from(...$spans) : Line::plain('');
    }

    private static function applySgrCode(Style $style, string $code): Style
    {
        return match ($code) {
            '0' => Style::new(),
            '1' => $style->bold(),
            '2' => $style->dim(),
            '3' => $style->italic(),
            '4' => $style->underline(),
            '30' => $style->fg(Color::named('black')),
            '31' => $style->fg(Color::indexed(167)),
            '32' => $style->fg(Color::indexed(71)),
            '33' => $style->fg(Color::indexed(179)),
            '34' => $style->fg(Color::indexed(68)),
            '35' => $style->fg(Color::indexed(133)),
            '36' => $style->fg(Color::indexed(73)),
            '37' => $style->fg(Color::indexed(250)),
            '90' => $style->fg(Color::indexed(245)),
            '91' => $style->fg(Color::indexed(203)),
            '92' => $style->fg(Color::indexed(114)),
            '93' => $style->fg(Color::indexed(221)),
            '94' => $style->fg(Color::indexed(111)),
            '95' => $style->fg(Color::indexed(176)),
            '96' => $style->fg(Color::indexed(116)),
            '97' => $style->fg(Color::brightWhite()),
            default => $style,
        };
    }
}
