<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Contract;

use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\HasStatusBar;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Tdom\Renderable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HasStatusBarTest extends TestCase
{
    #[Test]
    public function screenCanImplementHasStatusBar(): void
    {
        $screen = new class () implements Screen, HasStatusBar {
            public function __invoke(ScreenContext $ctx): Renderable
            {
                return \Phalanx\Theatron\Ui\text('Olympus');
            }

            public function statusBar(): Renderable
            {
                return \Phalanx\Theatron\Ui\text('-- NORMAL --');
            }
        };

        self::assertInstanceOf(Screen::class, $screen);
        self::assertInstanceOf(HasStatusBar::class, $screen);
    }

    #[Test]
    public function statusBarReturnsRenderable(): void
    {
        $screen = new class () implements HasStatusBar {
            public function statusBar(): Renderable
            {
                return \Phalanx\Theatron\Ui\text('Thermopylae');
            }
        };

        $result = $screen->statusBar();

        self::assertInstanceOf(Renderable::class, $result);
    }
}
