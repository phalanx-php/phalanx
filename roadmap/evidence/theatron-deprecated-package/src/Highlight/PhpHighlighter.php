<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Highlight;

use Phalanx\Theatron\Widget\Text\Line;
use Phalanx\Theatron\Widget\Text\Span;

final class PhpHighlighter implements Highlighter
{
    private const array KEYWORD_TOKENS = [
        T_ABSTRACT, T_AS, T_BREAK, T_CASE, T_CATCH, T_CLASS, T_CLONE, T_CONST,
        T_CONTINUE, T_DECLARE, T_DEFAULT, T_DO, T_ECHO, T_ELSE, T_ELSEIF,
        T_ENUM, T_EXTENDS, T_FINAL, T_FINALLY, T_FN, T_FOR, T_FOREACH,
        T_FUNCTION, T_IF, T_IMPLEMENTS, T_INTERFACE, T_MATCH, T_NAMESPACE,
        T_NEW, T_PRIVATE, T_PROTECTED, T_PUBLIC, T_READONLY, T_RETURN,
        T_STATIC, T_SWITCH, T_THROW, T_TRAIT, T_TRY, T_USE, T_WHILE, T_YIELD,
        T_YIELD_FROM, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE,
        T_INSTANCEOF, T_INSTEADOF, T_ARRAY, T_LIST, T_PRINT, T_UNSET,
        T_ISSET, T_EMPTY,
    ];

    private const array STRING_TOKENS = [
        T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE,
    ];

    private const array NUMBER_TOKENS = [
        T_LNUMBER, T_DNUMBER,
    ];

    private const array COMMENT_TOKENS = [
        T_COMMENT, T_DOC_COMMENT,
    ];

    private const array CLASS_CONTEXT_TOKENS = [
        T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS,
    ];

    public function __construct(
        private TokenStyle $tokenStyle = new TokenStyle(),
    ) {}

    /** @return list<Line> */
    public function highlight(string $code): array
    {
        $needsPrefix = !str_starts_with(trim($code), '<?');
        $source = $needsPrefix ? "<?php\n{$code}" : $code;

        $tokens = @token_get_all($source);
        $classContext = false;

        /** @var list<array{TokenType, string}> */
        $spans = [];

        foreach ($tokens as $token) {
            if (is_string($token)) {
                $classContext = false;
                $spans[] = [TokenType::Operator, $token];
                continue;
            }

            [$id, $text] = $token;

            if ($needsPrefix && $id === T_OPEN_TAG) {
                continue;
            }

            $type = self::classifyToken($id, $text, $classContext);

            if (in_array($id, self::CLASS_CONTEXT_TOKENS, true)) {
                $classContext = true;
            } elseif ($id === T_WHITESPACE) {
                // keep context through whitespace
            } elseif ($id === T_STRING && $classContext) {
                $type = TokenType::ClassName;
                $classContext = false;
            } else {
                $classContext = false;
            }

            $spans[] = [$type, $text];
        }

        return self::spansToLines($spans, $this->tokenStyle);
    }

    private static function classifyToken(int $id, string $text, bool $classContext): TokenType
    {
        if (in_array($id, self::KEYWORD_TOKENS, true)) {
            return TokenType::Keyword;
        }

        if (in_array($id, self::STRING_TOKENS, true)) {
            return TokenType::String;
        }

        if (in_array($id, self::NUMBER_TOKENS, true)) {
            return TokenType::Number;
        }

        if (in_array($id, self::COMMENT_TOKENS, true)) {
            return TokenType::Comment;
        }

        if ($id === T_VARIABLE) {
            return TokenType::Variable;
        }

        if ($id === T_STRING && $classContext) {
            return TokenType::ClassName;
        }

        if ($id === T_STRING) {
            $lower = strtolower($text);

            if ($lower === 'true' || $lower === 'false' || $lower === 'null') {
                return TokenType::Keyword;
            }
        }

        if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
            return TokenType::ClassName;
        }

        return TokenType::Default;
    }

    /**
     * @param list<array{TokenType, string}> $spans
     * @return list<Line>
     */
    private static function spansToLines(array $spans, TokenStyle $tokenStyle): array
    {
        $lines = [];
        $currentSpans = [];

        foreach ($spans as [$type, $text]) {
            $style = $tokenStyle->forToken($type);
            $parts = explode("\n", $text);

            foreach ($parts as $i => $part) {
                if ($i > 0) {
                    $lines[] = $currentSpans !== []
                        ? Line::from(...$currentSpans)
                        : Line::plain('');
                    $currentSpans = [];
                }

                if ($part !== '') {
                    $currentSpans[] = Span::styled($part, $style);
                }
            }
        }

        if ($currentSpans !== []) {
            $lines[] = Line::from(...$currentSpans);
        }

        if ($lines === []) {
            $lines[] = Line::plain('');
        }

        return $lines;
    }
}
