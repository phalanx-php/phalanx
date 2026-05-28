<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Highlight;

use Phalanx\Archon\Console\Style\Style as ConsoleStyle;
use Phalanx\Theatron\Style\Style;

final class TokenStyle
{
    private const array SPECS = [
        'Keyword'   => ['fg' => 'yellow',  'mods' => ['bold']],
        'String'    => ['fg' => 'green'],
        'Number'    => ['fg' => 'magenta'],
        'Comment'   => ['fg' => 'gray',    'mods' => ['italic']],
        'Variable'  => ['fg' => 'cyan'],
        'ClassName' => ['fg' => 'blue',    'mods' => ['underline']],
        'Operator'  => [],
        'Default'   => [],
    ];

    private const array CELL_MOD_MAP = [
        'bold'      => 'bold',
        'dim'       => 'dim',
        'italic'    => 'italic',
        'underline' => 'underline',
        'inverse'   => 'reverse',
        'strike'    => 'strikethrough',
    ];

    private const array CONSOLE_MOD_MAP = [
        'reverse'       => 'inverse',
        'strikethrough' => 'strike',
    ];

    /** @var array<string, Style> */
    private array $cellStyles;

    /** @var array<string, ConsoleStyle> */
    private array $consoleStyles;

    /** @param array<string, array{fg?: string, bg?: string, mods?: list<string>}>|null $overrides */
    public function __construct(?array $overrides = null)
    {
        $specs = $overrides !== null
            ? array_replace(self::SPECS, $overrides)
            : self::SPECS;

        $this->cellStyles = array_map(self::buildCellStyle(...), $specs);
        $this->consoleStyles = array_map(self::buildConsoleStyle(...), $specs);
    }

    public function forToken(TokenType $type): Style
    {
        return $this->cellStyles[$type->name] ?? Style::new();
    }

    public function forTokenDecoration(TokenType $type): ConsoleStyle
    {
        return $this->consoleStyles[$type->name] ?? ConsoleStyle::new();
    }

    /** @param array{fg?: string, bg?: string, mods?: list<string>} $spec */
    private static function buildCellStyle(array $spec): Style
    {
        $style = Style::new();

        if (isset($spec['fg'])) {
            $style = $style->fg($spec['fg']);
        }

        if (isset($spec['bg'])) {
            $style = $style->bg($spec['bg']);
        }

        foreach ($spec['mods'] ?? [] as $mod) {
            $method = self::CELL_MOD_MAP[$mod] ?? $mod;
            $style = $style->{$method}();
        }

        return $style;
    }

    /** @param array{fg?: string, bg?: string, mods?: list<string>} $spec */
    private static function buildConsoleStyle(array $spec): ConsoleStyle
    {
        $style = ConsoleStyle::new();

        if (isset($spec['fg'])) {
            $style = $style->fg($spec['fg']);
        }

        if (isset($spec['bg'])) {
            $style = $style->bg($spec['bg']);
        }

        foreach ($spec['mods'] ?? [] as $mod) {
            $method = self::CONSOLE_MOD_MAP[$mod] ?? $mod;
            $style = $style->{$method}();
        }

        return $style;
    }
}
