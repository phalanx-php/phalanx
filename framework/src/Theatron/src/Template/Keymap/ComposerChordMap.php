<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Keymap;

use Phalanx\Theatron\Input\KeyEvent;

final class ComposerChordMap
{
    /** @return list<KeymapEntry> */
    public static function entries(): array
    {
        return [
            new KeymapEntry('App', '^X ?', 'keymap'),
            new KeymapEntry('Queue', '^X u', 'undo'),
            new KeymapEntry('Queue', '^X a', 'undo all'),
            new KeymapEntry('App', '^X d', 'devtools'),
            new KeymapEntry('App', '^X s', 'settings'),
        ];
    }

    public static function actionFor(KeyEvent $event): ?ComposerChordAction
    {
        if ($event->ctrl || $event->alt) {
            return null;
        }

        return match (true) {
            $event->is('?') => ComposerChordAction::OpenKeymap,
            $event->is('u') => ComposerChordAction::UndoQueuedInput,
            $event->is('a') => ComposerChordAction::UndoAllQueuedInput,
            $event->is('d') => ComposerChordAction::OpenDevTools,
            $event->is('s') => ComposerChordAction::OpenSettings,
            default => null,
        };
    }

    public static function entryFor(string $combo): ?KeymapEntry
    {
        foreach (self::entries() as $entry) {
            if ($entry->combo === $combo) {
                return $entry;
            }
        }

        return null;
    }
}
