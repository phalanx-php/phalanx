<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Inputs;

use Phalanx\Tui\Tui\Core\AcceptsInput;
use Phalanx\Tui\Tui\Core\Focusable;
use Phalanx\Tui\Tui\Inputs\FocusManager;
use Phalanx\Tui\Tui\Inputs\InputMode;
use Phalanx\Tui\Tui\Inputs\Key;
use Phalanx\Tui\Tui\Inputs\KeyEvent;
use Phalanx\Tui\Tui\Inputs\ModeDispatcher;
use Phalanx\Tui\Tui\Inputs\NormalModeHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModeDispatcherTest extends TestCase
{
    #[Test]
    public function startsInNormalMode(): void
    {
        $focus = new FocusManager();
        $dispatcher = new ModeDispatcher($focus);

        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function syncModeWithActiveInputFocusEntersInsertMode(): void
    {
        $focus = new FocusManager();
        $focus->register('input', new class () implements Focusable, AcceptsInput {
            public function handleInput(KeyEvent $event): bool
            {
                return true;
            }
        });

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->syncModeWithActiveFocus();

        self::assertSame(InputMode::Insert, $dispatcher->mode);
    }

    #[Test]
    public function syncModeWithPlainFocusStaysNormalMode(): void
    {
        $focus = new FocusManager();
        $focus->register('plain', new class () implements Focusable {
        });

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->syncModeWithActiveFocus();

        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function restoreCanKeepInputFocusedInNormalMode(): void
    {
        $focus = new FocusManager();
        $focus->register('conversation', new class () implements Focusable {
        });
        $focus->register('input', new class () implements Focusable, AcceptsInput {
            public function handleInput(KeyEvent $event): bool
            {
                return true;
            }
        });

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->restore(InputMode::Normal, 'input');

        self::assertSame('input', $focus->activeName());
        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function restoreFallsBackToActiveFocusWhenSavedTargetIsMissing(): void
    {
        $focus = new FocusManager();
        $focus->register('conversation', new class () implements Focusable {
        });

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->restore(InputMode::Insert, 'input');

        self::assertSame('conversation', $focus->activeName());
        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function tabCyclesFocusInNormalMode(): void
    {
        $focus = new FocusManager();
        $focus->register('alpha', new class () implements Focusable {
        });
        $focus->register('beta', new class () implements Focusable {
        });

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent(key: Key::Tab));

        self::assertSame('beta', $focus->activeName());
    }

    #[Test]
    public function shiftTabCyclesFocusBackward(): void
    {
        $focus = new FocusManager();
        $focus->register('alpha', new class () implements Focusable {
        });
        $focus->register('beta', new class () implements Focusable {
        });
        $focus->register('gamma', new class () implements Focusable {
        });

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent(key: Key::Tab, shift: true));

        self::assertSame('gamma', $focus->activeName());
    }

    #[Test]
    public function iEntersInsertModeOnInputTarget(): void
    {
        $focus = new FocusManager();
        $focus->register('input', new class () implements Focusable, AcceptsInput {
            public function handleInput(KeyEvent $event): bool
            {
                return true;
            }
        });

        $dispatcher = new ModeDispatcher($focus);
        $result = $dispatcher->dispatch(new KeyEvent(key: 'i'));

        self::assertTrue($result);
        self::assertSame(InputMode::Insert, $dispatcher->mode);
    }

    #[Test]
    public function iDoesNothingOnPlainFocusable(): void
    {
        $focus = new FocusManager();
        $focus->register('plain', new class () implements Focusable {
        });

        $dispatcher = new ModeDispatcher($focus);
        $result = $dispatcher->dispatch(new KeyEvent(key: 'i'));

        self::assertFalse($result);
        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function escapeReturnsToNormalFromInsert(): void
    {
        $focus = new FocusManager();
        $focus->register('input', new class () implements Focusable, AcceptsInput {
            public function handleInput(KeyEvent $event): bool
            {
                return false;
            }
        });

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent(key: 'i'));
        self::assertSame(InputMode::Insert, $dispatcher->mode);

        $dispatcher->dispatch(new KeyEvent(key: Key::Escape));
        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function activeInputCanConsumeEscapeBeforeInsertModeFallback(): void
    {
        $focus = new FocusManager();
        $widget = new class () implements Focusable, AcceptsInput {
            public int $callCount = 0;

            public function handleInput(KeyEvent $event): bool
            {
                if (!$event->is(Key::Escape)) {
                    return false;
                }

                $this->callCount++;

                return true;
            }
        };
        $focus->register('input', $widget);

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent(key: 'i'));
        self::assertSame(InputMode::Insert, $dispatcher->mode);

        $dispatcher->dispatch(new KeyEvent(key: Key::Escape));

        self::assertSame(1, $widget->callCount);
        self::assertSame(InputMode::Insert, $dispatcher->mode);
    }

    #[Test]
    public function insertModeForwardsKeysToActive(): void
    {
        $focus = new FocusManager();

        $widget = new class () implements Focusable, AcceptsInput {
            public int $callCount = 0;

            public function handleInput(KeyEvent $event): bool
            {
                $this->callCount++;

                return true;
            }
        };

        $focus->register('input', $widget);

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent(key: 'i'));
        $dispatcher->dispatch(new KeyEvent(key: 'a'));

        self::assertSame(1, $widget->callCount);
    }

    #[Test]
    public function normalModeJkDispatchesToNormalModeHandler(): void
    {
        $focus = new FocusManager();

        $handler = new class () implements NormalModeHandler {
            public int $callCount = 0;

            public function handleNormalKey(KeyEvent $event): bool
            {
                $this->callCount++;

                return true;
            }
        };

        $focus->register('list', $handler);

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent(key: 'j'));
        $dispatcher->dispatch(new KeyEvent(key: 'k'));

        self::assertSame(2, $handler->callCount);
    }

    #[Test]
    public function normalModeHandlerCanHandleDirectionalKeysBeforeFocusNavigation(): void
    {
        $focus = new FocusManager();

        $handler = new class () implements NormalModeHandler {
            public int $callCount = 0;

            public function handleNormalKey(KeyEvent $event): bool
            {
                if (!$event->is(Key::Right)) {
                    return false;
                }

                $this->callCount++;

                return true;
            }
        };

        $focus->register('tabs', $handler);
        $focus->register('other', new class () implements Focusable {
        });

        $dispatcher = new ModeDispatcher($focus);
        $result = $dispatcher->dispatch(new KeyEvent(key: Key::Right));

        self::assertTrue($result);
        self::assertSame('tabs', $focus->activeName());
        self::assertSame(1, $handler->callCount);
    }

    #[Test]
    public function normalModeHandlerCanHandleEnterBeforeInsertFallback(): void
    {
        $focus = new FocusManager();

        $handler = new class () implements NormalModeHandler {
            public int $callCount = 0;

            public function handleNormalKey(KeyEvent $event): bool
            {
                if (!$event->is(Key::Enter)) {
                    return false;
                }

                $this->callCount++;

                return true;
            }
        };

        $focus->register('detail', $handler);

        $dispatcher = new ModeDispatcher($focus);
        $result = $dispatcher->dispatch(new KeyEvent(key: Key::Enter));

        self::assertTrue($result);
        self::assertSame(InputMode::Normal, $dispatcher->mode);
        self::assertSame(1, $handler->callCount);
    }

    #[Test]
    public function onModeChangeCallbackFires(): void
    {
        $focus = new FocusManager();
        $focus->register('input', new class () implements Focusable, AcceptsInput {
            public function handleInput(KeyEvent $event): bool
            {
                return true;
            }
        });

        $dispatcher = new ModeDispatcher($focus);

        $received = [];
        $dispatcher->onModeChange(static function (InputMode $mode, ?string $name) use (&$received): void {
            $received[] = [$mode, $name];
        });

        $dispatcher->dispatch(new KeyEvent(key: 'i'));

        self::assertCount(1, $received);
        self::assertSame(InputMode::Insert, $received[0][0]);
        self::assertSame('input', $received[0][1]);
    }

    #[Test]
    public function autoModeOnFocusChange(): void
    {
        $focus = new FocusManager();
        $focus->register('list', new class () implements Focusable {
        });
        $focus->register('input', new class () implements Focusable, AcceptsInput {
            public function handleInput(KeyEvent $event): bool
            {
                return true;
            }
        });

        $dispatcher = new ModeDispatcher($focus);

        // Tab to AcceptsInput — should auto-enter Insert
        $dispatcher->dispatch(new KeyEvent(key: Key::Tab));
        self::assertSame('input', $focus->activeName());
        self::assertSame(InputMode::Insert, $dispatcher->mode);

        // Tab back to plain Focusable — should revert to Normal
        $dispatcher->dispatch(new KeyEvent(key: Key::Tab));
        self::assertSame('list', $focus->activeName());
        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function hKeyNavigatesFocusBackward(): void
    {
        $focus = new FocusManager();
        $focus->register('alpha', new class () implements Focusable {
        });
        $focus->register('beta', new class () implements Focusable {
        });

        $dispatcher = new ModeDispatcher($focus);

        // Advance to beta first via 'l'.
        $dispatcher->dispatch(new KeyEvent(key: 'l'));
        self::assertSame('beta', $focus->activeName());

        // Press 'h' to go backward.
        $result = $dispatcher->dispatch(new KeyEvent(key: 'h'));

        self::assertTrue($result);
        self::assertSame('alpha', $focus->activeName());
    }

    #[Test]
    public function lKeyNavigatesFocusForward(): void
    {
        $focus = new FocusManager();
        $focus->register('alpha', new class () implements Focusable {
        });
        $focus->register('beta', new class () implements Focusable {
        });

        $dispatcher = new ModeDispatcher($focus);
        $result = $dispatcher->dispatch(new KeyEvent(key: 'l'));

        self::assertTrue($result);
        self::assertSame('beta', $focus->activeName());
    }

    #[Test]
    public function enterKeyEntersInsertModeOnInputTarget(): void
    {
        $focus = new FocusManager();
        $focus->register('input', new class () implements Focusable, AcceptsInput {
            public function handleInput(KeyEvent $event): bool
            {
                return true;
            }
        });

        $dispatcher = new ModeDispatcher($focus);
        $result = $dispatcher->dispatch(new KeyEvent(key: Key::Enter));

        self::assertTrue($result);
        self::assertSame(InputMode::Insert, $dispatcher->mode);
    }

    #[Test]
    public function tabInInsertModeCyclesFocusAndAutoSetsMode(): void
    {
        $focus = new FocusManager();
        $focus->register('input', new class () implements Focusable, AcceptsInput {
            public function handleInput(KeyEvent $event): bool
            {
                return true;
            }
        });
        $focus->register('list', new class () implements Focusable {
        });

        $dispatcher = new ModeDispatcher($focus);

        // Enter insert mode on AcceptsInput target.
        $dispatcher->dispatch(new KeyEvent(key: 'i'));
        self::assertSame(InputMode::Insert, $dispatcher->mode);

        // Tab in insert mode moves to plain Focusable — auto-switch to Normal.
        $result = $dispatcher->dispatch(new KeyEvent(key: Key::Tab));

        self::assertTrue($result);
        self::assertSame('list', $focus->activeName());
        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function ctrlPInInsertModeFocusesConversationBlocks(): void
    {
        $focus = new FocusManager();
        $focus->register('conversation', new class () implements NormalModeHandler {
            public function handleNormalKey(KeyEvent $event): bool
            {
                return false;
            }
        });
        $focus->register('input', new class () implements Focusable, AcceptsInput {
            public function handleInput(KeyEvent $event): bool
            {
                return true;
            }
        });
        $focus->focus('input');

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->syncModeWithActiveFocus();

        self::assertTrue($dispatcher->dispatch(new KeyEvent('p', ctrl: true)));
        self::assertSame('conversation', $focus->activeName());
        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function iInNormalModeReturnsToInputFocus(): void
    {
        $focus = new FocusManager();
        $focus->register('conversation', new class () implements NormalModeHandler {
            public function handleNormalKey(KeyEvent $event): bool
            {
                return false;
            }
        });
        $focus->register('input', new class () implements Focusable, AcceptsInput {
            public function handleInput(KeyEvent $event): bool
            {
                return true;
            }
        });

        $dispatcher = new ModeDispatcher($focus);

        self::assertTrue($dispatcher->dispatch(new KeyEvent('i')));
        self::assertSame('input', $focus->activeName());
        self::assertSame(InputMode::Insert, $dispatcher->mode);
    }
}
