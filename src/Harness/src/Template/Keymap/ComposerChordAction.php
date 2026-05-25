<?php

declare(strict_types=1);

namespace Phalanx\Harness\Template\Keymap;

enum ComposerChordAction
{
    case UndoQueuedInput;
    case UndoAllQueuedInput;
    case OpenDevTools;
    case OpenSettings;
    case OpenKeymap;
}
