<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Contract;

use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\Focusable;
use Phalanx\Theatron\Contract\HasFocusables;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Tdom\Renderable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HasFocusablesTest extends TestCase
{
    #[Test]
    public function screenCanImplementHasFocusables(): void
    {
        $screen = new class () implements Screen, HasFocusables {
            public function __invoke(ScreenContext $ctx): Renderable
            {
                return \Phalanx\Theatron\Ui\text('Sparta');
            }

            /** @return list<array{string, Focusable}> */
            public function focusables(): array
            {
                return [];
            }
        };

        self::assertInstanceOf(Screen::class, $screen);
        self::assertInstanceOf(HasFocusables::class, $screen);
    }

    #[Test]
    public function focusablesReturnsLabelledPairs(): void
    {
        $focusableA = new class () implements Focusable {
        };
        $focusableB = new class () implements Focusable {
        };

        $screen = new class ($focusableA, $focusableB) implements HasFocusables {
            public function __construct(
                private Focusable $a,
                private Focusable $b,
            ) {
            }

            /** @return list<array{string, Focusable}> */
            public function focusables(): array
            {
                return [
                    ['input', $this->a],
                    ['submit', $this->b],
                ];
            }
        };

        $pairs = $screen->focusables();

        self::assertCount(2, $pairs);
        self::assertSame('input', $pairs[0][0]);
        self::assertInstanceOf(Focusable::class, $pairs[0][1]);
        self::assertSame('submit', $pairs[1][0]);
        self::assertInstanceOf(Focusable::class, $pairs[1][1]);
    }

    #[Test]
    public function focusablesCanReturnEmptyList(): void
    {
        $screen = new class () implements HasFocusables {
            /** @return list<array{string, Focusable}> */
            public function focusables(): array
            {
                return [];
            }
        };

        self::assertSame([], $screen->focusables());
    }
}
