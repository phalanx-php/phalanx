<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Input;

use Phalanx\Theatron\Focus\FocusManager;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputTarget;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\ModeDispatcher;
use Phalanx\Theatron\Input\NormalModeHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModeDispatcherTest extends TestCase
{
    #[Test]
    public function starts_in_normal_mode(): void
    {
        $dispatcher = new ModeDispatcher(new FocusManager());

        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function h_moves_focus_backward(): void
    {
        $focus = new FocusManager();
        $focus->register('a', new DummyNormalHandler());
        $focus->register('b', new DummyNormalHandler());
        $focus->focus('b');

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent('h'));

        self::assertSame('a', $focus->activeName());
    }

    #[Test]
    public function l_moves_focus_forward(): void
    {
        $focus = new FocusManager();
        $focus->register('a', new DummyNormalHandler());
        $focus->register('b', new DummyNormalHandler());
        $focus->focus('a');

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent('l'));

        self::assertSame('b', $focus->activeName());
    }

    #[Test]
    public function tab_cycles_forward(): void
    {
        $focus = new FocusManager();
        $focus->register('a', new DummyNormalHandler());
        $focus->register('b', new DummyNormalHandler());
        $focus->focus('a');

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent(Key::Tab));

        self::assertSame('b', $focus->activeName());
    }

    #[Test]
    public function shift_tab_cycles_backward(): void
    {
        $focus = new FocusManager();
        $focus->register('a', new DummyNormalHandler());
        $focus->register('b', new DummyNormalHandler());
        $focus->focus('b');

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent(Key::Tab, shift: true));

        self::assertSame('a', $focus->activeName());
    }

    #[Test]
    public function j_dispatches_to_normal_handler(): void
    {
        $handler = new DummyNormalHandler();
        $focus = new FocusManager();
        $focus->register('panel', $handler);
        $focus->focus('panel');

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent('j'));

        self::assertTrue($handler->handled);
    }

    #[Test]
    public function i_enters_insert_mode_on_input_target(): void
    {
        $target = new DummyInputTarget();
        $focus = new FocusManager();
        $focus->register('input', $target);
        $focus->focus('input');

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent('i'));

        self::assertSame(InputMode::Insert, $dispatcher->mode);
    }

    #[Test]
    public function i_does_not_enter_insert_on_non_target(): void
    {
        $handler = new DummyNormalHandler();
        $focus = new FocusManager();
        $focus->register('panel', $handler);
        $focus->focus('panel');

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent('i'));

        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function escape_returns_to_normal(): void
    {
        $target = new DummyInputTarget();
        $focus = new FocusManager();
        $focus->register('input', $target);
        $focus->focus('input');

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent('i'));

        self::assertSame(InputMode::Insert, $dispatcher->mode);

        $dispatcher->dispatch(new KeyEvent(Key::Escape));

        self::assertSame(InputMode::Normal, $dispatcher->mode);
    }

    #[Test]
    public function insert_mode_routes_to_input_target(): void
    {
        $target = new DummyInputTarget();
        $focus = new FocusManager();
        $focus->register('input', $target);
        $focus->focus('input');

        $dispatcher = new ModeDispatcher($focus);
        $dispatcher->dispatch(new KeyEvent('i'));
        $dispatcher->dispatch(new KeyEvent('x'));

        self::assertTrue($target->handled);
    }
}

final class DummyNormalHandler implements NormalModeHandler
{
    public bool $handled = false;

    public function handleNormalKey(KeyEvent $event): bool
    {
        $this->handled = true;

        return true;
    }
}

final class DummyInputTarget implements InputTarget
{
    public bool $handled = false;

    public function handleInput(InputEvent $event): bool
    {
        $this->handled = true;

        return true;
    }
}
