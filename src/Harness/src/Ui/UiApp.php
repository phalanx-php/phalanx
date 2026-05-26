<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui;

use Phalanx\Harness\Ui\Screens\AgentBoardScreen;
use Phalanx\Harness\Ui\Screens\ChatScreen;
use Phalanx\Harness\Ui\Screens\ConversationBlockDetailScreen;
use Phalanx\Harness\Ui\Screens\DevToolsScreen;
use Phalanx\Harness\Ui\Screens\LlmRequestDetailScreen;
use Phalanx\Harness\Ui\Screens\SettingsScreen;
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\State\Store;

final class UiApp
{
    /** @return class-string<Store> */
    public static function store(): string
    {
        return AppStore::class;
    }

    /** @return list<class-string<Screen>> */
    public static function screens(): array
    {
        return [
            ChatScreen::class,
            ConversationBlockDetailScreen::class,
            AgentBoardScreen::class,
            DevToolsScreen::class,
            LlmRequestDetailScreen::class,
            SettingsScreen::class,
        ];
    }

    /** @return list<Binding> */
    public static function bindings(): array
    {
        return [
            Binding::ctrl('c')->quit()->label('quit'),
        ];
    }
}
