<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Highlight;

use Phalanx\Theatron\Style\Style;

final class TokenStyle
{
    /** @var array<string, Style> */
    private array $styles;

    /** @param array<string, Style>|null $overrides */
    public function __construct(?array $overrides = null)
    {
        $this->styles = $overrides ?? self::defaults();
    }

    public function forToken(TokenType $type): Style
    {
        return $this->styles[$type->name] ?? Style::new();
    }

    /** @return array<string, Style> */
    private static function defaults(): array
    {
        return [
            TokenType::Keyword->name => Style::new()->fg('yellow')->bold(),
            TokenType::String->name => Style::new()->fg('green'),
            TokenType::Number->name => Style::new()->fg('magenta'),
            TokenType::Comment->name => Style::new()->fg('gray')->italic(),
            TokenType::Variable->name => Style::new()->fg('cyan'),
            TokenType::ClassName->name => Style::new()->fg('blue')->underline(),
            TokenType::Operator->name => Style::new(),
            TokenType::Default->name => Style::new(),
        ];
    }
}
