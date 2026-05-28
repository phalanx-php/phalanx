<?php

declare(strict_types=1);

namespace Sentinel\Render;

use Tempest\Highlight\Highlighter;

final class CodeBlockFormatter
{
    private readonly Highlighter $highlighter;

    public function __construct()
    {
        $this->highlighter = new Highlighter(new DarkTerminalTheme());
    }

    public function format(string $text): string
    {
        $highlighter = $this->highlighter;

        return preg_replace_callback(
            '/```(\w+)(?:\s*\{([^}]+)\})?\n(.*?)```/s',
            static fn(array $m) => self::renderBlock($highlighter, $m[1], $m[3], self::parseLines($m[2] ?? '')),
            $text,
        ) ?? $text;
    }

    /** @param list<int> $highlightLines */
    private static function renderBlock(Highlighter $highlighter, string $lang, string $code, array $highlightLines): string
    {
        $code = rtrim($code);

        try {
            $highlighted = $highlighter->parse($code, $lang);
        } catch (\Throwable) {
            $highlighted = $code;
        }

        $lines = explode("\n", $highlighted);
        $gutterWidth = max(2, strlen((string) count($lines)));
        $output = [];

        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;
            $gutter = str_pad((string) $lineNum, $gutterWidth, ' ', STR_PAD_LEFT);
            $isHighlighted = in_array($lineNum, $highlightLines, true);

            if ($isHighlighted) {
                $output[] = "\033[1m" . $gutter . "\033[0m \033[48;5;236m" . $line . "\033[0m";
            } else {
                $output[] = "\033[90m" . $gutter . "\033[0m " . $line;
            }
        }

        return implode("\n", $output);
    }

    /** @return list<int> */
    private static function parseLines(string $spec): array
    {
        if ($spec === '') {
            return [];
        }

        return array_map('intval', explode(',', $spec));
    }
}
