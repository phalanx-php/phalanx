<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Keymap;

final class TemplateKeymap
{
    /** @return list<KeymapEntry> */
    public static function entries(): array
    {
        return [
            ...self::composerEntries(),
            ...self::queueEntries(),
            ...self::blockEntries(),
            ...self::appEntries(),
            new KeymapEntry('Overlay', 'Esc', 'close overlay'),
            new KeymapEntry('Overlay', 'q', 'close overlay'),
        ];
    }

    /** @return list<KeymapEntry> */
    private static function composerEntries(): array
    {
        return [
            new KeymapEntry('Composer', 'Enter', 'send'),
            new KeymapEntry('Composer', 'Shift+Enter', 'newline'),
            new KeymapEntry('Composer', 'Ctrl+U', 'clear before cursor'),
            new KeymapEntry('Composer', 'Ctrl+K', 'clear after cursor'),
            new KeymapEntry('Composer', 'Ctrl+W', 'delete word before cursor'),
            new KeymapEntry('Composer', 'Ctrl+Y', 'yank last kill'),
            new KeymapEntry('Composer', 'Alt+B/F', 'move by word'),
        ];
    }

    /** @return list<KeymapEntry> */
    private static function queueEntries(): array
    {
        return [
            self::chord('^X u'),
            self::chord('^X a'),
        ];
    }

    /** @return list<KeymapEntry> */
    private static function blockEntries(): array
    {
        return [
            new KeymapEntry('Blocks', 'Ctrl+P', 'focus activity blocks'),
            new KeymapEntry('Blocks', 'Up/Down', 'move focused block'),
            new KeymapEntry('Blocks', 'Enter', 'open focused block'),
            new KeymapEntry('Blocks', 'i', 'return to composer'),
        ];
    }

    /** @return list<KeymapEntry> */
    private static function appEntries(): array
    {
        return [
            self::chord('^X ?'),
            self::chord('^X d'),
            self::chord('^X s'),
            new KeymapEntry('App', 'Ctrl+C', 'quit'),
        ];
    }

    private static function chord(string $combo): KeymapEntry
    {
        return ComposerChordMap::entryFor($combo)
            ?? new KeymapEntry('App', $combo, '');
    }
}
