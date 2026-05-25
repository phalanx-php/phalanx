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
            ...self::devToolsEntries(),
            ...self::settingsEntries(),
            ...self::detailEntries(),
            ...self::agentBoardEntries(),
            ...self::effectEntries(),
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
            new KeymapEntry('Composer', 'Backspace', 'delete before cursor'),
            new KeymapEntry('Composer', 'Delete', 'delete at cursor'),
            new KeymapEntry('Composer', 'Left/Right', 'move cursor'),
            new KeymapEntry('Composer', 'Home/End', 'line start/end'),
            new KeymapEntry('Composer', 'Ctrl+A/E', 'line start/end'),
            new KeymapEntry('Composer', 'Ctrl+B/F', 'move cursor'),
            new KeymapEntry('Composer', 'Ctrl+D', 'delete at cursor'),
            new KeymapEntry('Composer', 'Ctrl+U', 'clear before cursor'),
            new KeymapEntry('Composer', 'Ctrl+K', 'clear after cursor'),
            new KeymapEntry('Composer', 'Ctrl+W', 'delete word before cursor'),
            new KeymapEntry('Composer', 'Ctrl+Y', 'yank last kill'),
            new KeymapEntry('Composer', 'Alt+B/F', 'move by word'),
            new KeymapEntry('Composer', 'Alt+D', 'delete next word'),
            new KeymapEntry('Composer', 'Alt+Backspace', 'delete previous word'),
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
    private static function devToolsEntries(): array
    {
        return [
            new KeymapEntry('DevTools', 'Left/Right', 'switch tab'),
            new KeymapEntry('DevTools', 'Up/Down', 'move request focus'),
            new KeymapEntry('DevTools', 'Enter', 'open request detail'),
            new KeymapEntry('DevTools', 'Esc', 'back'),
        ];
    }

    /** @return list<KeymapEntry> */
    private static function settingsEntries(): array
    {
        return [
            new KeymapEntry('Settings', 'Left/Right', 'switch tab'),
            new KeymapEntry('Settings', 'Up/Down', 'move item focus'),
            new KeymapEntry('Settings', 'Space', 'toggle item'),
            new KeymapEntry('Settings', 'Enter', 'toggle item'),
            new KeymapEntry('Settings', 'Esc', 'back'),
        ];
    }

    /** @return list<KeymapEntry> */
    private static function detailEntries(): array
    {
        return [
            new KeymapEntry('Detail', 'Up/Down', 'scroll request detail'),
            new KeymapEntry('Detail', 'Esc', 'back'),
        ];
    }

    /** @return list<KeymapEntry> */
    private static function agentBoardEntries(): array
    {
        return [
            new KeymapEntry('Agent Board', 'j/k', 'move agent focus'),
            new KeymapEntry('Agent Board', 'Up/Down', 'move agent focus'),
        ];
    }

    /** @return list<KeymapEntry> */
    private static function effectEntries(): array
    {
        return [
            new KeymapEntry('Effect Approval', 'A', 'approve effect'),
            new KeymapEntry('Effect Approval', 'D', 'deny effect'),
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
