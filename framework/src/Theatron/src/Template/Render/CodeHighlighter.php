<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Render;

use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class CodeHighlighter
{
    private const string JSON_TOKEN_PATTERN =
        '/("(?:[^"\\\\]|\\\\.)*"|\btrue\b|\bfalse\b|\bnull\b|-?\d+(?:\.\d+)?|[{}\[\],:])/';

    /** @return list<Line> */
    public function highlight(string $code, string $language = ''): array
    {
        return match (strtolower($language)) {
            'php' => $this->highlightPhp($code),
            'json' => $this->highlightJson($code),
            default => $this->plain($code),
        };
    }

    /**
     * @param list<Span> $current
     * @param list<Line> $lines
     */
    private static function appendText(string $text, Style $style, array &$current, array &$lines): void
    {
        $parts = explode("\n", $text);

        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $lines[] = $current !== [] ? Line::from(...$current) : Line::plain('');
                $current = [];
            }

            if ($part !== '') {
                $current[] = Span::styled($part, $style);
            }
        }
    }

    private static function phpStyle(int $token): Style
    {
        return match ($token) {
            T_ABSTRACT, T_AS, T_BREAK, T_CASE, T_CATCH, T_CLASS, T_CONST, T_CONTINUE,
            T_DECLARE, T_DEFAULT, T_DO, T_ELSE, T_ENUM, T_EXTENDS, T_FINAL, T_FINALLY,
            T_FN, T_FOR, T_FOREACH, T_FUNCTION, T_IF, T_IMPLEMENTS, T_INTERFACE,
            T_MATCH, T_NAMESPACE, T_NEW, T_PRIVATE, T_PROTECTED, T_PUBLIC, T_RETURN,
            T_STATIC, T_SWITCH, T_THROW, T_TRAIT, T_TRY, T_USE, T_WHILE, T_YIELD => Style::new()
                ->fg(Color::indexed(176))
                ->bold(),
            T_VARIABLE => Style::new()->fg(Color::indexed(116)),
            T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE => Style::new()->fg(Color::indexed(114)),
            T_LNUMBER, T_DNUMBER => Style::new()->fg(Color::indexed(215)),
            T_COMMENT, T_DOC_COMMENT => Style::new()->fg(Color::indexed(242))->italic(),
            default => self::bodyStyle(),
        };
    }

    private static function jsonStyle(string $token): Style
    {
        if (str_starts_with($token, '"')) {
            return Style::new()->fg(Color::indexed(114));
        }

        if (is_numeric($token)) {
            return Style::new()->fg(Color::indexed(215));
        }

        if ($token === 'true' || $token === 'false' || $token === 'null') {
            return Style::new()->fg(Color::indexed(176))->bold();
        }

        return self::operatorStyle();
    }

    private static function bodyStyle(): Style
    {
        return Style::new()->fg(Color::indexed(252));
    }

    private static function operatorStyle(): Style
    {
        return Style::new()->fg(Color::indexed(245));
    }

    /** @return list<Line> */
    private function highlightPhp(string $code): array
    {
        $needsPrefix = !str_starts_with(trim($code), '<?');
        $source = $needsPrefix ? "<?php\n{$code}" : $code;
        $tokens = @token_get_all($source);
        $lines = [];
        $current = [];

        foreach ($tokens as $token) {
            if (is_string($token)) {
                self::appendText($token, self::operatorStyle(), $current, $lines);
                continue;
            }

            [$id, $text] = $token;

            if ($needsPrefix && $id === T_OPEN_TAG) {
                continue;
            }

            self::appendText($text, self::phpStyle($id), $current, $lines);
        }

        if ($current !== []) {
            $lines[] = Line::from(...$current);
        }

        return $lines ?: [Line::plain('')];
    }

    /** @return list<Line> */
    private function highlightJson(string $code): array
    {
        $lines = [];

        foreach (explode("\n", $code) as $line) {
            $spans = [];
            preg_match_all(self::JSON_TOKEN_PATTERN, $line, $matches, PREG_OFFSET_CAPTURE);
            $offset = 0;

            foreach ($matches[0] as [$token, $pos]) {
                if ($pos > $offset) {
                    $spans[] = Span::styled(substr($line, $offset, $pos - $offset), self::bodyStyle());
                }

                $spans[] = Span::styled($token, self::jsonStyle($token));
                $offset = $pos + strlen($token);
            }

            if ($offset < strlen($line)) {
                $spans[] = Span::styled(substr($line, $offset), self::bodyStyle());
            }

            $lines[] = $spans !== [] ? Line::from(...$spans) : Line::plain('');
        }

        return $lines;
    }

    /** @return list<Line> */
    private function plain(string $code): array
    {
        return array_map(
            static fn(string $line): Line => Line::from(Span::styled($line, self::bodyStyle())),
            explode("\n", $code),
        );
    }
}
