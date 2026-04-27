<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Writer;

final class DisplayWidth
{
    private const string ANSI_PATTERN = '/\x1B\[[0-9;?]*[ -\/]*[@-~]|\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/';

    public static function of(string $text): int
    {
        $stripped = self::stripAnsi($text);

        if (function_exists('mb_strwidth')) {
            return mb_strwidth($stripped);
        }

        return mb_strlen($stripped);
    }

    public static function stripAnsi(string $text): string
    {
        return (string) preg_replace(self::ANSI_PATTERN, '', $text);
    }

    public static function truncate(string $text, int $maxWidth, string $ellipsis = '...'): string
    {
        $width = self::of($text);

        if ($width <= $maxWidth) {
            return $text;
        }

        $ellipsisWidth = self::of($ellipsis);

        if ($maxWidth <= $ellipsisWidth) {
            return mb_substr($ellipsis, 0, $maxWidth);
        }

        $target = $maxWidth - $ellipsisWidth;
        $result = '';
        $currentWidth = 0;
        $len = mb_strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1);
            $charWidth = function_exists('mb_strwidth') ? mb_strwidth($char) : 1;

            if ($currentWidth + $charWidth > $target) {
                break;
            }

            $result .= $char;
            $currentWidth += $charWidth;
        }

        return $result . $ellipsis;
    }
}
