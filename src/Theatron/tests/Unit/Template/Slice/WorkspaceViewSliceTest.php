<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template\Slice;

use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputModeSlice;
use Phalanx\Theatron\Template\Screen\ChatScreen;
use Phalanx\Theatron\Template\Screen\DevToolsScreen;
use Phalanx\Theatron\Template\Slice\ConversationSlice;
use Phalanx\Theatron\Template\Slice\WorkspaceViewSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkspaceViewSliceTest extends TestCase
{
    #[Test]
    public function chatScrollIsClampedToAvailableTurns(): void
    {
        $conversation = $this->conversationWithTurns(2);
        $view = new WorkspaceViewSlice();

        $view = $view
            ->scrollChatUp($conversation)
            ->scrollChatUp($conversation)
            ->scrollChatUp($conversation);

        self::assertSame(1, $view->chatScrollOffset);
        self::assertSame('turn_1', $view->focusedTurn($conversation)?->id);

        $view = $view
            ->scrollChatDown()
            ->scrollChatDown();

        self::assertSame(0, $view->chatScrollOffset);
        self::assertSame('turn_2', $view->focusedTurn($conversation)?->id);
    }

    #[Test]
    public function expandedAndSelectedTurnsAreSeparateViewConcerns(): void
    {
        $conversation = $this->conversationWithTurns(3);
        $view = (new WorkspaceViewSlice())
            ->scrollChatUp($conversation)
            ->expandFocusedChatTurn($conversation)
            ->selectFocusedChatTurn($conversation, returnTarget: ChatScreen::class);

        self::assertSame('turn_2', $view->expandedTurnId);
        self::assertSame('turn_2', $view->selectedTurnId);
        self::assertSame(ChatScreen::class, $view->returnTarget);
        self::assertSame('turn_2', $view->selectedTurn($conversation)?->id);
    }

    #[Test]
    public function staleSelectedTurnFallsBackToNull(): void
    {
        $view = new WorkspaceViewSlice(selectedTurnId: 'turn_missing');

        self::assertNull($view->selectedTurn($this->conversationWithTurns(1)));
    }

    #[Test]
    public function inputModeSnapshotsAreStoredPerWorkspace(): void
    {
        $view = (new WorkspaceViewSlice())
            ->withInputMode(ChatScreen::class, InputMode::Insert, 'input')
            ->withInputMode(DevToolsScreen::class, InputMode::Normal, 'devtools');

        $chatMode = $view->inputModeFor(ChatScreen::class);
        $devtoolsMode = $view->inputModeFor(DevToolsScreen::class);

        self::assertInstanceOf(InputModeSlice::class, $chatMode);
        self::assertInstanceOf(InputModeSlice::class, $devtoolsMode);
        self::assertSame(InputMode::Insert, $chatMode->mode);
        self::assertSame('input', $chatMode->focusTarget);
        self::assertSame(InputMode::Normal, $devtoolsMode->mode);
        self::assertSame('devtools', $devtoolsMode->focusTarget);
    }

    private function conversationWithTurns(int $count): ConversationSlice
    {
        $conversation = new ConversationSlice();

        for ($i = 1; $i <= $count; $i++) {
            $conversation = $conversation
                ->addUserMessage('turn ' . $i)
                ->appendToken('answer ' . $i)
                ->finalizeMessage();
        }

        return $conversation;
    }
}
