<?php

declare(strict_types=1);

namespace Phalanx\Harness\Template;

use Phalanx\Harness\Template\Screen\AgentBoardScreen;
use Phalanx\Harness\Template\Screen\ChatScreen;
use Phalanx\Harness\Template\Screen\ConversationBlockDetailScreen;
use Phalanx\Harness\Template\Screen\DevToolsScreen;
use Phalanx\Harness\Template\Screen\LlmRequestDetailScreen;
use Phalanx\Harness\Template\Screen\SettingsScreen;
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\State\Store;

final class TemplateApp
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
