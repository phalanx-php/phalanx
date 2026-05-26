<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Screens;

use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\NormalModeHandler;

final class ChatConversationHandler implements NormalModeHandler
{
    public function __construct(
        private ChatScreen $screen,
    ) {
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        if ($event->is(Key::Enter)) {
            return $this->screen->openFocusedActivityBlock();
        }

        return $this->screen->handleScroll($event);
    }
}
