<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Template\Screen;

use DateTimeImmutable;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Persistence\EffectLogRecord;
use Phalanx\Harness\Template\AppStore;
use Phalanx\Harness\Template\Screen\ChatConversationHandler;
use Phalanx\Harness\Template\Screen\ChatInputHandler;
use Phalanx\Harness\Template\Screen\ChatScreen;
use Phalanx\Harness\Template\Screen\ConversationBlockDetailScreen;
use Phalanx\Harness\Template\Slice\ActivitySlice;
use Phalanx\Harness\Template\Slice\ActivityStatus;
use Phalanx\Harness\Template\Slice\ConversationSlice;
use Phalanx\Harness\Template\Slice\RuntimeStatusSlice;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use Phalanx\Panoply\Cue\Effect\Authorized as EffectAuthorized;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Failed as EffectFailed;
use Phalanx\Panoply\Cue\Effect\Paused as EffectPaused;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Provider\Resolved as ProviderResolved;
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputModeSlice;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Modifier;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\InputElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\SpinnerElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Text\Line;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatScreenTest extends TestCase
{
    #[Test]
    public function rendersEmptyConversationWithReplShell(): void
    {
        $store = new AppStore();
        $screen = new ChatScreen($store);
        $result = $screen($this->makeContext($store));

        self::assertInstanceOf(ColumnElement::class, $result);
        self::assertCount(7, $result->children);

        $text = self::flatten($result);
        self::assertStringContainsString('Type a message to begin.', $text);
        self::assertStringNotContainsString('Theatron', $text);
        self::assertStringNotContainsString('Powered by Phalanx PHP', $text);
        self::assertStringNotContainsString('Λ̬', $text);
        self::assertStringContainsString('Λ idle', $text);
        self::assertStringContainsString('+> ', $text);
    }

    #[Test]
    public function rendersSummariesAndExpandedCurrentExchange(): void
    {
        $store = new AppStore();
        $store->conversation = new ConversationSlice()
            ->addUserMessage('adding a couple messages')
            ->appendToken('Strategic Guidance The Hellespont is optimal for a flanking maneuver.')
            ->finalizeMessage()
            ->addUserMessage('and more')
            ->appendToken('Second preview should show in history.')
            ->finalizeMessage()
            ->addUserMessage('and another')
            ->appendToken('Final answer stays expanded.');

        $screen = new ChatScreen($store);

        $text = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('> adding a couple messages', $text);
        self::assertStringContainsString('Strategic Guidance The Hellespont', $text);
        self::assertStringContainsString('> and more', $text);
        self::assertStringContainsString('Second preview should show', $text);
        self::assertStringContainsString('you: and another', $text);
        self::assertStringContainsString('assistant:', $text);
        self::assertStringContainsString('Final answer stays expanded.', $text);
    }

    #[Test]
    public function streamingThinkingRendersBoundedEphemeralRows(): void
    {
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('think through this')
            ->appendToken(
                'thought-01 thought-02 thought-03 thought-04 thought-05 '
                . 'thought-06 thought-07 thought-08 thought-09 thought-10',
                'thinking',
            );
        $screen = new ChatScreen($store);

        $text = self::flatten($screen($this->makeContext($store, width: 24, height: 30)));

        self::assertStringContainsString('thinking.', $text);
        self::assertStringNotContainsString('thought-01', $text);
        self::assertStringNotContainsString('thought-02', $text);
        self::assertStringNotContainsString('thought-03', $text);
        self::assertStringContainsString('thought-04', $text);
        self::assertStringContainsString('thought-10', $text);
    }

    #[Test]
    public function streamingThinkingUsesPulseFrameForDots(): void
    {
        foreach ([0 => 'thinking.', 3 => 'thinking..', 6 => 'thinking...', 9 => 'thinking.'] as $ticks => $label) {
            $store = new AppStore();
            $activity = new ActivitySlice(status: ActivityStatus::Running);

            for ($i = 0; $i < $ticks; $i++) {
                $activity = $activity->tick();
            }

            $store->activity = $activity;
            $store->conversation = (new ConversationSlice())
                ->addUserMessage('think with motion')
                ->appendToken('deliberating', 'thinking');
            $screen = new ChatScreen($store);

            self::assertSame(
                [$label],
                self::spinnerLabels($screen($this->makeContext($store))),
            );
        }
    }

    #[Test]
    public function streamingThinkingStaysBeforeFinalAnswerProjection(): void
    {
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('answer after thinking')
            ->appendToken('quiet deliberation', 'thinking')
            ->appendToken('final answer');
        $screen = new ChatScreen($store);

        $text = self::flatten($screen($this->makeContext($store)));
        $buffer = self::paint(
            $screen($this->makeContext($store, width: 50, height: 24)),
            width: 50,
            height: 24,
        );

        self::assertStringContainsString('thinking.', $text);
        self::assertStringContainsString('quiet deliberation', $text);
        self::assertStringContainsString('assistant:', $text);
        self::assertStringContainsString('final answer', $text);
        self::assertLessThan(
            strpos($text, 'assistant:'),
            strpos($text, 'quiet deliberation'),
        );
        self::assertGreaterThan(
            self::findRowContaining($buffer, 'thinking.'),
            self::findRowContaining($buffer, 'final answer'),
        );
    }

    #[Test]
    public function completedThinkingIsHiddenUnlessExplicitlyEnabled(): void
    {
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('finished turn')
            ->appendToken('hidden reasoning', 'thinking')
            ->appendToken('visible answer')
            ->finalizeMessage();
        $screen = new ChatScreen($store);

        $defaultText = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('visible answer', $defaultText);
        self::assertStringNotContainsString('thinking.', $defaultText);
        self::assertStringNotContainsString('hidden reasoning', $defaultText);

        $store->workspaceView = $store->workspaceView->toggleThinking();

        $expandedText = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('hidden reasoning', $expandedText);
        self::assertStringNotContainsString('thinking.', $expandedText);
    }

    #[Test]
    public function rendersTurnProjectionEventsAfterAssistantAnswer(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('Use the tool')
            ->appendToken('Tool result summarized.')
            ->appendCue(new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                kind: Kind::FileRead,
                summary: 'Read a strategy note',
                arguments: ['path' => 'notes/strategy.md'],
                requiresApproval: true,
            ))
            ->appendCue(new EffectAuthorized(
                id: 'cue_2',
                sequence: 2,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                grantId: 'grant_1',
            ))
            ->appendCue(new EffectExecuted(
                id: 'cue_3',
                sequence: 3,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                durationMs: 42,
                resultDigest: 'sha256:abc',
            ))
            ->appendCue(new ProviderResolved(
                id: 'cue_4',
                sequence: 4,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                provider: 'openai',
                model: 'gpt-5.1',
                reasonCode: 'configured',
            ))
            ->appendCue(new UsageDelta(
                id: 'cue_5',
                sequence: 5,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                inputTokens: 1,
                outputTokens: 2,
            ))
            ->appendCue(new FinalUsage(
                id: 'cue_6',
                sequence: 6,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                inputTokens: 12,
                outputTokens: 24,
                cacheReadTokens: 3,
                cacheWriteTokens: 4,
                costUsd: 0.01,
            ))
            ->finalizeMessage();
        $screen = new ChatScreen($store);

        $text = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('Tool result summarized.', $text);
        self::assertStringContainsString('effect executed: file.read eff_1', $text);
        self::assertStringContainsString('Read a strategy note', $text);
        self::assertStringContainsString('grant grant_1', $text);
        self::assertStringContainsString('42ms', $text);
        self::assertStringContainsString('sha256:abc', $text);
        self::assertStringContainsString('usage: 12 in', $text);
        self::assertStringContainsString('cache read 3', $text);
        self::assertStringContainsString('cache write 4', $text);
        self::assertStringNotContainsString('effect approved', $text);
        self::assertStringNotContainsString('openai gpt-5.1', $text);
        self::assertStringNotContainsString('1 in · 2 out', $text);
        self::assertStringNotContainsString('notes/strategy.md', $text);
        self::assertLessThan(
            strpos($text, 'effect executed: file.read eff_1'),
            strpos($text, 'Tool result summarized.'),
        );
    }

    #[Test]
    public function rendersPausedDeniedAndFailedEffectLifecyclesInThread(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('Show effect outcomes')
            ->appendCue(new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_paused',
                kind: Kind::FileWrite,
                summary: 'Write config',
                requiresApproval: true,
            ))
            ->appendCue(new EffectPaused(
                id: 'cue_2',
                sequence: 2,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_paused',
                reason: 'Approval required',
            ))
            ->appendCue(new EffectRequested(
                id: 'cue_3',
                sequence: 3,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_denied',
                kind: Kind::ShellExec,
                summary: 'Run deploy',
                requiresApproval: true,
            ))
            ->appendCue(new EffectDenied(
                id: 'cue_4',
                sequence: 4,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_denied',
                reasonCodes: ['policy', 'user-denied'],
            ))
            ->appendCue(new EffectRequested(
                id: 'cue_5',
                sequence: 5,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_failed',
                kind: Kind::CodeSearch,
                summary: 'Search code',
            ))
            ->appendCue(new EffectFailed(
                id: 'cue_6',
                sequence: 6,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_failed',
                reason: 'Tool crashed',
                errorClass: 'RuntimeException',
            ));
        $screen = new ChatScreen($store);

        $text = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('approval needed: file.write eff_paused', $text);
        self::assertStringContainsString('Approval required', $text);
        self::assertStringContainsString('effect denied: shell.exec eff_denied', $text);
        self::assertStringContainsString('policy, user-denied', $text);
        self::assertStringContainsString('effect failed: code.search eff_failed', $text);
        self::assertStringContainsString('RuntimeException', $text);
    }

    #[Test]
    public function rendersTurnProjectionFailuresWithoutDroppingAnswerText(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('Try a risky turn')
            ->appendToken('Partial answer survived.')
            ->appendCue(new ActivityFailed(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                reason: 'Provider stream failed',
                errorClass: 'RuntimeException',
            ));
        $screen = new ChatScreen($store);

        $text = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('Partial answer survived.', $text);
        self::assertStringContainsString('activity failed: Provider stream failed', $text);
        self::assertStringContainsString('RuntimeException', $text);
    }

    #[Test]
    public function rendersAthenaRuntimeResolutionFromEffectLogs(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('Run tools')
            ->appendToken('Tool work complete.')
            ->appendEffectLog(new EffectLogRecord(
                id: 'effect_log_1',
                invocationId: 'inv_1',
                kind: 'tool_call',
                toolName: 'read_file',
                argsHash: 'sha256:abc',
                resolution: Resolution::LocalTool,
                outcome: 'ok',
                at: $at,
            ))
            ->appendEffectLog(new EffectLogRecord(
                id: 'effect_log_2',
                invocationId: 'inv_1',
                kind: 'tool_call',
                toolName: 'search_docs',
                argsHash: 'sha256:def',
                resolution: Resolution::McpTool,
                outcome: 'failed',
                at: $at,
            ));
        $screen = new ChatScreen($store);

        $text = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('Tool work complete.', $text);
        self::assertStringContainsString('local tool: tool_call · local-tool read_file · ok', $text);
        self::assertStringContainsString('mcp tool: tool_call · mcp-tool search_docs · failed', $text);
    }

    #[Test]
    public function streamingThinkingRemainsEphemeralWhenThinkingIsExpanded(): void
    {
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('streaming expanded turn')
            ->appendToken('single-streaming-thought', 'thinking');
        $store->workspaceView = $store->workspaceView->toggleThinking();
        $screen = new ChatScreen($store);

        $text = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('thinking.', $text);
        self::assertSame(1, substr_count($text, 'single-streaming-thought'));
    }

    #[Test]
    public function streamingThinkingRowsUseMutedStyle(): void
    {
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('style the stream')
            ->appendToken('muted-streaming-thought', 'thinking');
        $screen = new ChatScreen($store);

        $line = self::findLineContaining(
            $screen($this->makeContext($store)),
            'muted-streaming-thought',
        );

        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Dim));
        self::assertTrue($line->spans[0]->style->foreground?->equals(Color::indexed(242)));
    }

    #[Test]
    public function paintsLatestConversationRowsAtBottomAboveComposer(): void
    {
        $store = new AppStore();
        $store->conversation = new ConversationSlice()
            ->addUserMessage('bottom anchored message')
            ->appendToken('short answer');
        $screen = new ChatScreen($store);
        $buffer = self::paint(
            $screen($this->makeContext($store, width: 80, height: 30)),
            width: 80,
            height: 30,
        );

        $answerRow = self::findRowContaining($buffer, 'short answer');
        $statusRow = self::findRowContaining($buffer, 'Λ idle');
        $inputRow = self::findRowContaining($buffer, '+>');

        self::assertSame(22, $answerRow);
        self::assertSame(25, $statusRow);
        self::assertSame(27, $inputRow);
    }

    #[Test]
    public function conversationBottomAnchorRecalculatesFromContextHeight(): void
    {
        $store = new AppStore();
        $store->conversation = new ConversationSlice()
            ->addUserMessage('resized message')
            ->appendToken('resized answer');
        $screen = new ChatScreen($store);

        $short = self::paint(
            $screen($this->makeContext($store, width: 80, height: 20)),
            width: 80,
            height: 20,
        );
        $tall = self::paint(
            $screen($this->makeContext($store, width: 80, height: 32)),
            width: 80,
            height: 32,
        );

        $shortAnswerRow = self::findRowContaining($short, 'resized answer');
        $tallAnswerRow = self::findRowContaining($tall, 'resized answer');

        self::assertSame(self::findRowContaining($short, 'Λ idle') - 3, $shortAnswerRow);
        self::assertSame(self::findRowContaining($tall, 'Λ idle') - 3, $tallAnswerRow);
        self::assertGreaterThan($shortAnswerRow, $tallAnswerRow);
    }

    #[Test]
    public function multilineComposerPaintsQueuedTextAboveRule(): void
    {
        $store = new AppStore();
        $screen = new ChatScreen($store);
        $screen->inputText->set("msg1\n\nmsg2\n\nmsg3");
        $buffer = self::paint(
            $screen($this->makeContext($store, width: 120, height: 30)),
            width: 120,
            height: 30,
        );

        self::assertSame(23, self::findRowContaining($buffer, 'msg1'));
        self::assertSame(25, self::findRowContaining($buffer, 'msg2'));
        self::assertSame(27, self::findRowContaining($buffer, 'msg3'));
        self::assertSame(28, self::findRowContaining($buffer, '╴╴╴'));
    }

    #[Test]
    public function composerPaintsSelectedText(): void
    {
        $store = new AppStore();
        $screen = new ChatScreen($store);
        $screen->inputText->set('alpha beta');
        $screen->inputCursor->set(10);
        $screen->inputSelectionAnchor->set(6);
        $buffer = self::paint(
            $screen($this->makeContext($store, width: 120, height: 24)),
            width: 120,
            height: 24,
        );

        $row = self::findRowContaining($buffer, 'alpha beta');

        self::assertFalse($buffer->get(10, $row)->style->hasModifier(Modifier::Reverse));
        self::assertTrue($buffer->get(11, $row)->style->hasModifier(Modifier::Reverse));
        self::assertTrue($buffer->get(14, $row)->style->hasModifier(Modifier::Reverse));
    }

    #[Test]
    public function composerRuleExtendsToStatusControlEnd(): void
    {
        $store = new AppStore();
        $screen = new ChatScreen($store);
        $screen->inputText->set("msg1\n\nmsg2\n\nmsg3");
        $buffer = self::paint(
            $screen($this->makeContext($store, width: 120, height: 30)),
            width: 120,
            height: 30,
        );

        $ruleRow = self::bufferRow($buffer, self::findRowContaining($buffer, '╴╴╴'));

        self::assertSame(min(120, mb_strlen(self::flatten($screen->statusBar()))), mb_strlen($ruleRow));
        self::assertGreaterThan(30, mb_substr_count($ruleRow, '╴'));
    }

    #[Test]
    public function statusBarRendersSituationalHud(): void
    {
        $store = new AppStore();
        $store->activity = $store->activity->withModelName('qwen3:4b');
        $store->inputMode = new InputModeSlice(InputMode::Insert, 'input');
        $store->runtimeStatus = new RuntimeStatusSlice('/home/sparta/project', '/home/sparta');
        $screen = new ChatScreen($store);

        $text = self::flatten($screen->statusBar());

        self::assertStringContainsString('(Λ)', $text);
        self::assertStringContainsString('qwen3:4b', $text);
        self::assertStringContainsString('~/project', $text);
        self::assertStringContainsString('mem', $text);
        self::assertStringContainsString('alloc', $text);
        self::assertStringContainsString('insert', $text);
        self::assertStringContainsString('^X ? keymap', $text);
        self::assertMatchesRegularExpression(
            '/\(Λ\) qwen3:4b\s+~\/project\s+mem [0-9,.]+(?:B|KB|MB) · alloc [0-9,.]+(?:B|KB|MB)\s+insert\s+\^X \? keymap/',
            $text,
        );
        self::assertDoesNotMatchRegularExpression('/(?:mem|alloc) [0-9,.]+ (?:B|KB|MB)/', $text);
        self::assertStringNotContainsString('^P blocks', $text);
        self::assertStringNotContainsString('^X u undo', $text);
        self::assertStringNotContainsString('^X a undo all', $text);
        self::assertStringNotContainsString('^X d devtools', $text);
        self::assertStringNotContainsString('^X s settings', $text);
        self::assertStringNotContainsString('Enter send', $text);
        self::assertStringNotContainsString('^C quit', $text);
    }

    #[Test]
    public function statusBarKeepsActivityBlockControlsWhenConversationIsFocused(): void
    {
        $store = new AppStore();
        $store->inputMode = new InputModeSlice(InputMode::Normal, 'conversation');
        $screen = new ChatScreen($store);

        $text = self::flatten($screen->statusBar());

        self::assertStringContainsString('↑ focus', $text);
        self::assertStringContainsString('↓ focus', $text);
        self::assertStringContainsString('Enter open', $text);
        self::assertStringContainsString('i compose', $text);
        self::assertStringContainsString('^C quit', $text);
        self::assertStringNotContainsString('(Λ)', $text);
        self::assertStringNotContainsString('mem', $text);
        self::assertStringNotContainsString('^X ? keymap', $text);
    }

    #[Test]
    public function focusablesExposeConversationAndInputHandlers(): void
    {
        $screen = new ChatScreen(new AppStore());

        $focusables = $screen->focusables();

        self::assertCount(2, $focusables);
        self::assertSame('conversation', $focusables[0][0]);
        self::assertInstanceOf(ChatConversationHandler::class, $focusables[0][1]);
        self::assertSame('input', $focusables[1][0]);
        self::assertInstanceOf(ChatInputHandler::class, $focusables[1][1]);
    }

    #[Test]
    public function scrollingMovesThroughConversationExchanges(): void
    {
        $store = new AppStore();
        $store->conversation = $this->conversationWithUserMessages(4);
        $screen = new ChatScreen($store);

        self::assertSame(0, $store->workspaceView->chatScrollOffset);

        self::assertTrue($screen->handleScroll(new KeyEvent(Key::Up)));
        self::assertSame(1, $store->workspaceView->chatScrollOffset);

        self::assertTrue($screen->handleScroll(new KeyEvent('k')));
        self::assertSame(2, $store->workspaceView->chatScrollOffset);

        self::assertTrue($screen->handleScroll(new KeyEvent(Key::Down)));
        self::assertSame(1, $store->workspaceView->chatScrollOffset);

        self::assertTrue($screen->handleScroll(new KeyEvent('j')));
        self::assertSame(0, $store->workspaceView->chatScrollOffset);
    }

    #[Test]
    public function conversationEnterOpensFocusedActivityBlockDetailScreen(): void
    {
        $store = new AppStore();
        $store->conversation = new ConversationSlice()
            ->addUserMessage('first message')
            ->appendToken('first answer')
            ->finalizeMessage()
            ->addUserMessage('second message')
            ->appendToken('second answer')
            ->finalizeMessage();
        $navigator = new ChatScreenRecordingNavigator();
        $screen = new ChatScreen($store);
        $screen($this->makeContext($store, navigator: $navigator));
        $handler = new ChatConversationHandler($screen);

        self::assertTrue($handler->handleNormalKey(new KeyEvent(Key::Enter)));

        self::assertSame(ConversationBlockDetailScreen::class, $navigator->target);
        self::assertNull($store->workspaceView->expandedTurnId);
        self::assertSame('turn_2', $store->workspaceView->selectedTurnId);
    }

    #[Test]
    public function conversationBlockDetailRendersSelectedTurnProjection(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('first message')
            ->appendToken('first answer')
            ->finalizeMessage()
            ->addUserMessage('second message')
            ->appendToken('second answer')
            ->appendCue(new ActivityStarted(
                id: 'cue_0',
                sequence: 0,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
            ))
            ->appendCue(new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                kind: Kind::FileRead,
                summary: 'Read a strategy note',
                arguments: ['path' => 'notes/strategy.md'],
            ))
            ->appendCue(new FinalUsage(
                id: 'cue_2',
                sequence: 2,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                inputTokens: 20,
                outputTokens: 30,
                cacheReadTokens: 2,
            ))
            ->finalizeMessage();
        $store->workspaceView = $store->workspaceView->selectFocusedChatTurn($store->conversation);
        $screen = new ConversationBlockDetailScreen($store);

        $text = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('you: second message', $text);
        self::assertStringContainsString('second answer', $text);
        self::assertStringContainsString('events:', $text);
        self::assertStringContainsString('cue_0 activity.started cue.activity.started', $text);
        self::assertStringContainsString('cue_1 effect.requested cue.effect.requested', $text);
        self::assertStringContainsString('Read a strategy note', $text);
        self::assertStringContainsString('args {"path":"notes\\/strategy.md"}', $text);
        self::assertStringContainsString('cue_2 usage.final cue.usage.final', $text);
        self::assertStringContainsString('cache read 2', $text);
        self::assertStringNotContainsString('first answer', $text);
    }

    #[Test]
    public function selectedConversationBlockDoesNotCollapseChatThread(): void
    {
        $store = new AppStore();
        $store->conversation = new ConversationSlice()
            ->addUserMessage('first message')
            ->appendToken('first answer')
            ->finalizeMessage()
            ->addUserMessage('second message')
            ->appendToken('second answer')
            ->finalizeMessage()
            ->addUserMessage('third message')
            ->appendToken('third answer')
            ->finalizeMessage();
        $store->workspaceView = $store->workspaceView
            ->scrollChatUp($store->conversation)
            ->selectFocusedChatTurn($store->conversation);
        $screen = new ChatScreen($store);

        $text = self::flatten($screen($this->makeContext($store)));

        self::assertSame('turn_2', $store->workspaceView->selectedTurnId);
        self::assertStringContainsString('> first message', $text);
        self::assertStringContainsString('> second message', $text);
        self::assertStringContainsString('you: third message', $text);
        self::assertStringContainsString('third answer', $text);
    }

    #[Test]
    public function submitInputAddsUserMessageAndStartsActivity(): void
    {
        $store = new AppStore();
        $store->conversation = $this->conversationWithUserMessages(2);
        $store->workspaceView = $store->workspaceView
            ->scrollChatUp($store->conversation)
            ->expandFocusedChatTurn($store->conversation)
            ->selectFocusedChatTurn($store->conversation);
        $screen = new ChatScreen($store);

        $screen->inputText->set('Rally the phalanx at the agora.');

        self::assertTrue($screen->submitInput());
        self::assertCount(3, $store->conversation->messages);
        self::assertCount(3, $store->conversation->turns);
        self::assertSame('user', $store->conversation->messages[2]->role);
        self::assertSame('Rally the phalanx at the agora.', $store->conversation->messages[2]->text);
        self::assertSame('Rally the phalanx at the agora.', $store->conversation->turns[2]->userText);
        self::assertSame(0, $store->workspaceView->chatScrollOffset);
        self::assertNull($store->workspaceView->expandedTurnId);
        self::assertNull($store->workspaceView->selectedTurnId);
        self::assertSame(ActivityStatus::Running, $store->activity->status);
        self::assertSame('', $screen->inputText->get());
        self::assertSame('', $store->input->text);
    }

    #[Test]
    public function submitInputQueuesWhenActivityIsBusy(): void
    {
        $store = new AppStore();
        $store->activity = new ActivitySlice()->withStatus(ActivityStatus::Running);
        $screen = new ChatScreen($store);

        $screen->inputText->set('Queue this while thinking.');

        self::assertTrue($screen->submitInput());
        self::assertSame([], $store->conversation->messages);
        self::assertSame(['Queue this while thinking.'], $store->input->queue);
    }

    #[Test]
    public function inputHandlerEnterSubmitsText(): void
    {
        $store = new AppStore();
        $screen = new ChatScreen($store);
        $handler = new ChatInputHandler($screen);

        $screen->inputText->set('Form the phalanx.');

        self::assertTrue($handler->handleInput(new KeyEvent(Key::Enter)));
        self::assertCount(1, $store->conversation->messages);
        self::assertSame('Form the phalanx.', $store->conversation->messages[0]->text);
    }

    #[Test]
    public function inputHandlerEnterExpandsScrolledHistoryInsteadOfSubmitting(): void
    {
        $store = new AppStore();
        $store->conversation = $this->conversationWithUserMessages(3);
        $store->workspaceView = $store->workspaceView->scrollChatUp($store->conversation);
        $screen = new ChatScreen($store);
        $handler = new ChatInputHandler($screen);

        $screen->inputText->set('This should not submit yet.');

        self::assertTrue($handler->handleInput(new KeyEvent(Key::Enter)));
        self::assertSame('turn_2', $store->workspaceView->expandedTurnId);
        self::assertNull($store->workspaceView->selectedTurnId);
        self::assertCount(3, $store->conversation->messages);
    }

    #[Test]
    public function inputHandlerDelegatesTextEditingAndSyncsInputSlice(): void
    {
        $store = new AppStore();
        $screen = new ChatScreen($store);
        $handler = new ChatInputHandler($screen);

        $handler->handleInput(new KeyEvent('Z'));
        $handler->handleInput(new KeyEvent('e'));
        $handler->handleInput(new KeyEvent('u'));
        $handler->handleInput(new KeyEvent('s'));

        self::assertSame('Zeus', $screen->inputText->get());
        self::assertSame('Zeus', $store->input->text);

        $handler->handleInput(new KeyEvent(Key::Backspace));

        self::assertSame('Zeu', $screen->inputText->get());
        self::assertSame('Zeu', $store->input->text);
    }

    #[Test]
    public function keySequencePrefixIsVisibleInSharedStatusState(): void
    {
        $store = new AppStore();
        $screen = new ChatScreen($store);

        self::assertFalse($store->keySequence->isAwaitingControlX());

        $screen->beginInputChordPrefix();

        self::assertTrue($store->keySequence->isAwaitingControlX());
        self::assertStringContainsString('^X …', self::flatten($screen($this->makeContext($store))));

        $screen->clearInputChordPrefix();

        self::assertFalse($store->keySequence->isAwaitingControlX());
        self::assertStringNotContainsString('^X …', self::flatten($screen($this->makeContext($store))));
    }

    #[Test]
    public function inputHandlerCtrlUpDoesNotRestoreQueuedMessages(): void
    {
        $store = new AppStore();
        $store->input = $store->input
            ->enqueue('first queued')
            ->enqueue('second queued');
        $screen = new ChatScreen($store);
        $handler = new ChatInputHandler($screen);

        self::assertFalse($handler->handleInput(new KeyEvent(Key::Up, ctrl: true)));

        self::assertSame('', $screen->inputText->get());
        self::assertSame('', $store->input->text);
        self::assertSame(['first queued', 'second queued'], $store->input->queue);
    }

    #[Test]
    public function thinkingStatusLineShowsQueueCount(): void
    {
        $store = new AppStore();
        $store->activity = new ActivitySlice()->withStatus(ActivityStatus::Running);
        $screen = new ChatScreen($store);
        $screen->inputText->set('first');
        $screen->submitInput();
        $screen->inputText->set('second');
        $screen->submitInput();

        $text = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('Λ running', $text);
        self::assertStringContainsString('2 queued', $text);
    }

    #[Test]
    public function queuedStatusLineShowsRestoreHintsOnlyWhenComposerIsEmpty(): void
    {
        $store = new AppStore();
        $store->input = $store->input
            ->enqueue('first queued')
            ->enqueue('second queued');
        $screen = new ChatScreen($store);

        $emptyComposerText = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('2 queued', $emptyComposerText);
        self::assertStringContainsString('^X u undo', $emptyComposerText);
        self::assertStringContainsString('^X a undo all', $emptyComposerText);

        $screen->inputText->set('draft');
        $screen->syncInputText();

        $draftComposerText = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('2 queued', $draftComposerText);
        self::assertStringNotContainsString('^X u undo', $draftComposerText);
        self::assertStringNotContainsString('^X a undo all', $draftComposerText);
    }

    #[Test]
    public function singleQueuedStatusLineShowsOnlyRestoreLastHint(): void
    {
        $store = new AppStore();
        $store->input = $store->input->enqueue('only queued');
        $screen = new ChatScreen($store);

        $text = self::flatten($screen($this->makeContext($store)));

        self::assertStringContainsString('1 queued', $text);
        self::assertStringContainsString('^X u undo', $text);
        self::assertStringNotContainsString('^X a undo all', $text);
    }

    private static function flatten(Renderable|string $renderable): string
    {
        if (is_string($renderable)) {
            return $renderable;
        }

        if ($renderable instanceof TextElement) {
            return self::lineToText($renderable->content);
        }

        if ($renderable instanceof InputElement) {
            return self::lineToText($renderable->prompt) . $renderable->value;
        }

        if ($renderable instanceof SpinnerElement) {
            return self::lineToText($renderable->label ?? '');
        }

        if ($renderable instanceof ColumnElement || $renderable instanceof RowElement) {
            return implode("\n", array_map(self::flatten(...), $renderable->children));
        }

        if ($renderable instanceof PanelElement) {
            return self::lineToText($renderable->title) . "\n" . self::flatten($renderable->child);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function spinnerLabels(Renderable|string $renderable): array
    {
        if (is_string($renderable)) {
            return [];
        }

        if ($renderable instanceof SpinnerElement) {
            return [$renderable->label === null ? '' : self::lineToText($renderable->label)];
        }

        if ($renderable instanceof ColumnElement || $renderable instanceof RowElement) {
            return array_merge([], ...array_map(self::spinnerLabels(...), $renderable->children));
        }

        if ($renderable instanceof PanelElement) {
            return self::spinnerLabels($renderable->child);
        }

        return [];
    }

    private static function findLineContaining(Renderable|string $renderable, string $needle): Line
    {
        $line = self::lineContaining($renderable, $needle);

        if ($line !== null) {
            return $line;
        }

        self::fail(sprintf('Unable to find "%s" in render tree.', $needle));
    }

    private static function lineContaining(Renderable|string $renderable, string $needle): ?Line
    {
        if (is_string($renderable)) {
            return null;
        }

        if ($renderable instanceof TextElement && str_contains(self::lineToText($renderable->content), $needle)) {
            return is_string($renderable->content)
                ? Line::plain($renderable->content)
                : $renderable->content;
        }

        if ($renderable instanceof ColumnElement || $renderable instanceof RowElement) {
            foreach ($renderable->children as $child) {
                $line = self::lineContaining($child, $needle);

                if ($line !== null) {
                    return $line;
                }
            }
        }

        if ($renderable instanceof PanelElement) {
            return self::lineContaining($renderable->child, $needle);
        }

        return null;
    }

    private static function lineToText(string|Line $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return implode('', array_map(static fn($span): string => $span->content, $content->spans));
    }

    private static function paint(Renderable $renderable, int $width, int $height): Buffer
    {
        $buffer = Buffer::empty($width, $height);

        Painter::paint(
            $renderable,
            new PaintContext(Rect::sized($width, $height), $buffer),
        );

        return $buffer;
    }

    private static function findRowContaining(Buffer $buffer, string $needle): int
    {
        for ($y = 0; $y < $buffer->height; $y++) {
            if (str_contains(self::bufferRow($buffer, $y), $needle)) {
                return $y;
            }
        }

        self::fail(sprintf('Unable to find "%s" in painted buffer.', $needle));
    }

    private static function bufferRow(Buffer $buffer, int $y): string
    {
        $line = '';

        for ($x = 0; $x < $buffer->width; $x++) {
            $line .= $buffer->get($x, $y)->char;
        }

        return rtrim($line);
    }

    private function conversationWithUserMessages(int $count): ConversationSlice
    {
        $conversation = new ConversationSlice();

        for ($i = 1; $i <= $count; $i++) {
            $conversation = $conversation->addUserMessage("Message {$i}.");
        }

        return $conversation;
    }

    private function makeContext(
        AppStore $store,
        int $width = 120,
        int $height = 24,
        ?Navigator $navigator = null,
    ): ScreenContext {
        $scope = $this->createStub(TaskScope::class);
        $navigator ??= $this->createStub(Navigator::class);
        $mountSystem = new MountSystem($scope);

        return new ScreenContext($scope, Theme::default(), $navigator, $mountSystem, width: $width, height: $height);
    }
}

final class ChatScreenRecordingNavigator implements Navigator
{
    /** @var class-string|null */
    public ?string $target = null;

    /** @var class-string|null */
    public ?string $overlay = null;

    public function go(string $screen): void
    {
        $this->target = $screen;
    }

    public function back(): bool
    {
        return false;
    }

    public function overlay(string $component, mixed ...$params): void
    {
        $this->overlay = $component;
    }

    public function dismiss(): void
    {
    }

    public function dismissAll(): void
    {
    }

    public function active(): string
    {
        return ChatScreen::class;
    }
}
