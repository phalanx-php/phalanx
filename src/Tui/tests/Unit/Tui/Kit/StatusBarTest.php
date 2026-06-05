<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Kit;

use Phalanx\Tui\Tui\Kit\StatusBar;
use Phalanx\Tui\Tui\Styles\Color;
use Phalanx\Tui\Tui\Tdom\Element\StatusLineElement;
use Phalanx\Tui\Tui\Tdom\Element\TextElement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StatusBarTest extends TestCase
{
    #[Test]
    public function newCreatesInstance(): void
    {
        self::assertInstanceOf(StatusBar::class, StatusBar::new());
    }

    #[Test]
    public function newAcceptsCustomBackground(): void
    {
        $bar = StatusBar::new(Color::indexed(240));

        self::assertInstanceOf(StatusBar::class, $bar);
    }

    #[Test]
    public function sectionAddsEntry(): void
    {
        $bar = StatusBar::new();
        $result = $bar->section('hello');

        self::assertSame($bar, $result);
    }

    #[Test]
    public function leftAddsFilledSection(): void
    {
        $bar = StatusBar::new();
        $result = $bar->left('left text');

        self::assertSame($bar, $result);
    }

    #[Test]
    public function rightAddsUnfilledSection(): void
    {
        $bar = StatusBar::new();
        $result = $bar->right('right text');

        self::assertSame($bar, $result);
    }

    #[Test]
    public function renderProducesStatusLineElement(): void
    {
        $bar = StatusBar::new()->section('status');
        $element = $bar->render();

        self::assertInstanceOf(StatusLineElement::class, $element);
    }

    #[Test]
    public function renderSectionCountMatchesAddedSections(): void
    {
        $bar = StatusBar::new()
            ->left('a')
            ->section('b')
            ->right('c');

        $element = $bar->render();

        self::assertCount(3, $element->sections);
    }

    #[Test]
    public function renderWithNoSectionsProducesEmptyStatusLine(): void
    {
        $element = StatusBar::new()->render();

        self::assertCount(0, $element->sections);
    }

    #[Test]
    public function leftSectionTextIsPreserved(): void
    {
        $element = StatusBar::new()->left('Thermopylae')->render();

        self::assertCount(1, $element->sections);
        $section = $element->sections[0];
        self::assertInstanceOf(TextElement::class, $section);
        self::assertSame('Thermopylae', $section->content);
    }
}
