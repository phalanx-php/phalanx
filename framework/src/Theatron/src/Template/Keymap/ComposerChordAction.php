<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Keymap;

enum ComposerChordAction
{
    case UndoQueuedInput;
    case UndoAllQueuedInput;
    case OpenDevTools;
    case OpenSettings;
    case OpenKeymap;
}
