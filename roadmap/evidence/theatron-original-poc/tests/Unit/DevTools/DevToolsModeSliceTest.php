<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\DevTools;

use Phalanx\Theatron\DevTools\DevToolsMode;
use Phalanx\Theatron\DevTools\DevToolsModeSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DevToolsModeSliceTest extends TestCase
{
    #[Test]
    public function defaults_to_docked(): void
    {
        $slice = new DevToolsModeSlice();

        self::assertSame(DevToolsMode::Docked, $slice->mode);
    }

    #[Test]
    public function accepts_mode(): void
    {
        $slice = new DevToolsModeSlice(DevToolsMode::Overlay);

        self::assertSame(DevToolsMode::Overlay, $slice->mode);
    }

    #[Test]
    public function slice_key(): void
    {
        self::assertSame('theatron.devtools.mode', (new DevToolsModeSlice())->key);
    }
}
