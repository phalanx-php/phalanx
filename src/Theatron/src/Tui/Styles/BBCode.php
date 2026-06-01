<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Styles;

use Phalanx\Theatron\Tui\Styles\Line;
use Phalanx\Theatron\Tui\Styles\Span;
use Phalanx\Theatron\Tui\Styles\Style as AnsiStyle;

final class BBCode
{
    public static function parse(string $input, Theme $theme): Line
    {
        if (!str_contains($input, '[')) {
            return Line::plain($input);
        }

        /** @var list<AnsiStyle> $stack */
        $stack = [AnsiStyle::new()];
        /** @var list<Span> $spans */
        $spans = [];
        $buffer = '';
        $tag = '';
        $inTag = false;
        $len = strlen($input);
        $i = 0;

        while ($i < $len) {
            $ch = $input[$i];

            if ($inTag) {
                if ($ch === ']') {
                    if ($tag === '/') {
                        if (count($stack) > 1) {
                            array_pop($stack);
                        }
                    } elseif ($tag !== '') {
                        $resolved = self::resolveTag($tag, $theme);
                        if ($resolved !== null) {
                            $top = $stack[count($stack) - 1];
                            $stack[] = $top->patch($resolved);
                        }
                    }
                    $tag = '';
                    $inTag = false;
                } else {
                    $tag .= $ch;
                }
                $i++;
                continue;
            }

            if ($ch === '[') {
                if (isset($input[$i + 1]) && $input[$i + 1] === '[') {
                    $buffer .= '[';
                    $i += 2;
                    continue;
                }

                if ($buffer !== '') {
                    $spans[] = new Span($buffer, $stack[count($stack) - 1]);
                    $buffer = '';
                }
                $inTag = true;
                $i++;
                continue;
            }

            $buffer .= $ch;
            $i++;
        }

        if ($buffer !== '') {
            $spans[] = new Span($buffer, $stack[count($stack) - 1]);
        }

        return $spans === [] ? Line::plain('') : Line::from(...$spans);
    }

    private static function resolveTag(string $tag, Theme $theme): ?AnsiStyle
    {
        $tokens = preg_split('/\s+/', trim($tag), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            return null;
        }

        $result = AnsiStyle::new();
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token === 'on') {
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null) {
                    if (str_starts_with($next, '#')) {
                        $result = $result->patch(AnsiStyle::new()->bg($next));
                    } else {
                        $named = $theme->resolve($next);
                        if ($named !== null) {
                            $bg = $named->foreground ?? $named->background;
                            if ($bg !== null) {
                                $result = $result->patch(AnsiStyle::new()->bg($bg));
                            }
                        }
                    }
                    $i += 2;
                    continue;
                }
                $i++;
                continue;
            }

            if (str_starts_with($token, '#')) {
                $result = $result->patch(AnsiStyle::new()->fg($token));
            } else {
                $named = $theme->resolve($token);
                if ($named !== null) {
                    $result = $result->patch($named);
                }
            }

            $i++;
        }

        return $result->isEmpty ? null : $result;
    }
}
