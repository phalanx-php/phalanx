<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Phalanx\Terminal\Input\EventParser;
use Phalanx\Terminal\Input\Key;
use Phalanx\Terminal\Input\KeyEvent;
use Phalanx\Terminal\Input\PasteEvent;

/**
 * Translates raw stdin bytes into canonical key name strings that prompts switch on.
 *
 * Bracketed paste is split into individual codepoints so text inputs insert
 * naturally without special-casing paste in each prompt implementation.
 *
 * Canonical names: named keys use Key enum backing values ('enter', 'up', 'f1', …),
 * ctrl combos produce 'ctrl-{letter}', printable chars pass through as-is.
 */
final class KeyParser
{
    private readonly EventParser $parser;

    public function __construct()
    {
        $this->parser = new EventParser();
    }

    /** @return list<string> */
    public function parse(string $data): array
    {
        $keys = [];

        foreach ($this->parser->parse($data) as $event) {
            if ($event instanceof KeyEvent) {
                $key = self::keyEventToString($event);
                if ($key !== null) {
                    $keys[] = $key;
                }
                continue;
            }

            if ($event instanceof PasteEvent) {
                foreach (mb_str_split($event->text) as $char) {
                    if ($char === "\r" || $char === "\n") {
                        $keys[] = 'enter';
                    } elseif ($char === "\t") {
                        $keys[] = 'tab';
                    } elseif (mb_ord($char) >= 32) {
                        $keys[] = $char;
                    }
                }
            }
            // MouseEvent and ResizeEvent intentionally dropped — prompts don't consume them.
        }

        return $keys;
    }

    private static function keyEventToString(KeyEvent $event): ?string
    {
        $key = $event->key;

        if ($event->ctrl && is_string($key) && mb_strlen($key) === 1) {
            return 'ctrl-' . $key;
        }

        // Alt+arrow word motion — handle both terminal encodings:
        //   Terminal.app sends ESC+b/f  → KeyEvent('b'/'f', alt: true)
        //   iTerm2 sends ESC[1;3D/C     → KeyEvent(Key::Left/Right, alt: true)
        if ($event->alt) {
            if ($key === Key::Left  || $key === 'b') return 'alt-left';
            if ($key === Key::Right || $key === 'f') return 'alt-right';
        }

        if ($key instanceof Key) {
            return $key->value;
        }

        if (is_string($key) && mb_strlen($key) === 1 && mb_ord($key) >= 32) {
            return $key;
        }

        return null;
    }
}
