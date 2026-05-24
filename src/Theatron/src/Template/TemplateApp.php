<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template;

use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\State\Store;
use Phalanx\Theatron\Template\Screen\AgentBoardScreen;
use Phalanx\Theatron\Template\Screen\ChatScreen;
use Phalanx\Theatron\Template\Screen\ConversationBlockDetailScreen;
use Phalanx\Theatron\Template\Screen\DevToolsScreen;
use Phalanx\Theatron\Template\Screen\LlmRequestDetailScreen;
use Phalanx\Theatron\Template\Screen\SettingsScreen;

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
