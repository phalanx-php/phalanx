<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Inputs;

use Phalanx\Tui\Core\AcceptsInput;
use Phalanx\Tui\Core\Focusable;
use Phalanx\Tui\Inputs\FocusManager;
use Phalanx\Tui\Inputs\Key;
use Phalanx\Tui\Inputs\KeyEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusManagerTest extends TestCase
{
    #[Test]
    public function registerAndCount(): void
    {
        $manager = new FocusManager();
        $manager->register('alpha', new class () implements Focusable {
        });
        $manager->register('beta', new class () implements Focusable {
        });
        $manager->register('gamma', new class () implements Focusable {
        });

        self::assertSame(3, $manager->count);
    }

    #[Test]
    public function activeReturnsFirstByDefault(): void
    {
        $manager = new FocusManager();
        $first = new class () implements Focusable {
        };
        $second = new class () implements Focusable {
        };

        $manager->register('first', $first);
        $manager->register('second', $second);

        self::assertSame($first, $manager->active());
    }

    #[Test]
    public function nextCyclesForward(): void
    {
        $manager = new FocusManager();
        $manager->register('alpha', new class () implements Focusable {
        });
        $manager->register('beta', new class () implements Focusable {
        });
        $manager->register('gamma', new class () implements Focusable {
        });

        self::assertSame('alpha', $manager->activeName());

        $manager->next();
        self::assertSame('beta', $manager->activeName());

        $manager->next();
        self::assertSame('gamma', $manager->activeName());

        $manager->next();
        self::assertSame('alpha', $manager->activeName());
    }

    #[Test]
    public function previousCyclesBackward(): void
    {
        $manager = new FocusManager();
        $manager->register('alpha', new class () implements Focusable {
        });
        $manager->register('beta', new class () implements Focusable {
        });
        $manager->register('gamma', new class () implements Focusable {
        });

        $manager->previous();
        self::assertSame('gamma', $manager->activeName());

        $manager->previous();
        self::assertSame('beta', $manager->activeName());
    }

    #[Test]
    public function focusByName(): void
    {
        $manager = new FocusManager();
        $manager->register('alpha', new class () implements Focusable {
        });
        $manager->register('beta', new class () implements Focusable {
        });
        $manager->register('gamma', new class () implements Focusable {
        });

        $manager->focus('gamma');

        self::assertSame('gamma', $manager->activeName());
    }

    #[Test]
    public function focusUnknownNameDoesNothing(): void
    {
        $manager = new FocusManager();
        $manager->register('alpha', new class () implements Focusable {
        });
        $manager->register('beta', new class () implements Focusable {
        });

        $manager->focus('nonexistent');

        self::assertSame('alpha', $manager->activeName());
    }

    #[Test]
    public function dispatchToAcceptsInput(): void
    {
        $manager = new FocusManager();

        $widget = new class implements Focusable, AcceptsInput {
            public int $callCount = 0;

            public function handleInput(KeyEvent $event): bool
            {
                $this->callCount++;

                return true;
            }
        };

        $manager->register('input-widget', $widget);

        $result = $manager->dispatch(new KeyEvent(key: Key::Enter));

        self::assertTrue($result);
        self::assertSame(1, $widget->callCount);
    }

    #[Test]
    public function dispatchToNonInputReturnsFalse(): void
    {
        $manager = new FocusManager();
        $manager->register('plain', new class () implements Focusable {
        });

        $result = $manager->dispatch(new KeyEvent(key: Key::Enter));

        self::assertFalse($result);
    }

    #[Test]
    public function namesReturnsRegisteredNames(): void
    {
        $manager = new FocusManager();
        $manager->register('leonidas', new class () implements Focusable {
        });
        $manager->register('thermopylae', new class () implements Focusable {
        });
        $manager->register('sparta', new class () implements Focusable {
        });

        self::assertSame(['leonidas', 'thermopylae', 'sparta'], $manager->names());
    }

    #[Test]
    public function onFocusChangedCallback(): void
    {
        $manager = new FocusManager();
        $manager->register('alpha', new class () implements Focusable {
        });
        $manager->register('beta', new class () implements Focusable {
        });

        $received = [];
        $manager->onFocusChanged(static function (string $name) use (&$received): void {
            $received[] = $name;
        });

        $manager->next();
        $manager->next();
        $manager->focus('alpha');

        self::assertSame(['beta', 'alpha', 'alpha'], $received);
    }

    #[Test]
    public function emptyManager(): void
    {
        $manager = new FocusManager();

        $manager->next();
        $manager->previous();

        self::assertNull($manager->active());
        self::assertNull($manager->activeName());
        self::assertSame(0, $manager->count);
    }

    #[Test]
    public function resetClearsAllFocusables(): void
    {
        $manager = new FocusManager();
        $manager->register('a', new class () implements Focusable {
        });
        $manager->register('b', new class () implements Focusable {
        });
        self::assertSame(2, $manager->count);

        $manager->reset();

        self::assertSame(0, $manager->count);
        self::assertNull($manager->active());
        self::assertNull($manager->activeName());
        self::assertSame([], $manager->names());
    }

    #[Test]
    public function singleItemCyclesToSelf(): void
    {
        $manager = new FocusManager();
        $solo = new class () implements Focusable {
        };
        $manager->register('solo', $solo);

        $manager->next();
        self::assertSame('solo', $manager->activeName());
        self::assertSame($solo, $manager->active());

        $manager->previous();
        self::assertSame('solo', $manager->activeName());
        self::assertSame($solo, $manager->active());
    }
}
