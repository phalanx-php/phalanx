<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Buffer;

use Phalanx\Theatron\Buffer\Cell;
use Phalanx\Theatron\Style\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CellTransparencyTest extends TestCase
{
    #[Test]
    public function new_cell_is_transparent(): void
    {
        $cell = new Cell();

        self::assertTrue($cell->transparent);
    }

    #[Test]
    public function set_makes_cell_opaque(): void
    {
        $cell = new Cell();
        $cell->set('X', Style::new());

        self::assertFalse($cell->transparent);
    }

    #[Test]
    public function reset_restores_transparency(): void
    {
        $cell = new Cell();
        $cell->set('X', Style::new());
        $cell->reset();

        self::assertTrue($cell->transparent);
    }

    #[Test]
    public function copy_from_preserves_transparent_flag(): void
    {
        $opaque = new Cell();
        $opaque->set('A', Style::new());

        $target = new Cell();
        $target->copyFrom($opaque);
        self::assertFalse($target->transparent);

        $fresh = new Cell();
        $target->copyFrom($fresh);
        self::assertTrue($target->transparent);
    }
}
