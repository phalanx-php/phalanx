<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Highlight;

use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Themes\EscapesTerminalTheme;
use Tempest\Highlight\Themes\TerminalStyle;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final class DarkTerminalTheme implements TerminalTheme
{
    use EscapesTerminalTheme;

    public function before(TokenType $tokenType): string
    {
        $style = match ($tokenType) {
            TokenTypeEnum::KEYWORD => TerminalStyle::FG_MAGENTA,
            TokenTypeEnum::TYPE => TerminalStyle::FG_CYAN,
            TokenTypeEnum::VALUE => TerminalStyle::FG_GREEN,
            TokenTypeEnum::NUMBER => TerminalStyle::FG_YELLOW,
            TokenTypeEnum::LITERAL => TerminalStyle::FG_BLUE,
            TokenTypeEnum::PROPERTY => TerminalStyle::FG_CYAN,
            TokenTypeEnum::GENERIC => TerminalStyle::FG_WHITE,
            TokenTypeEnum::COMMENT => TerminalStyle::FG_GRAY,
            default => TerminalStyle::RESET,
        };

        return TerminalStyle::ESC->value . $style->value;
    }

    public function after(TokenType $tokenType): string
    {
        return TerminalStyle::ESC->value . TerminalStyle::RESET->value;
    }
}
