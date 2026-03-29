<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Tests\Unit\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Widget\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    #[Test]
    public function renders_headers_and_rows(): void
    {
        $table = new Table(['Name', 'Age']);
        $table->addRow('Alice', '30');
        $table->addRow('Bob', '25');

        $buf = Buffer::empty(40, 10);
        $table->render(Rect::sized(40, 10), $buf);

        self::assertSame('N', $buf->get(0, 0)->char);
        self::assertSame('─', $buf->get(0, 1)->char);
        self::assertSame('A', $buf->get(0, 2)->char);
    }

    #[Test]
    public function renders_separator_between_header_and_rows(): void
    {
        $table = new Table(['X']);
        $table->addRow('1');

        $buf = Buffer::empty(20, 5);
        $table->render(Rect::sized(20, 5), $buf);

        self::assertSame('─', $buf->get(0, 1)->char);
    }

    #[Test]
    public function too_small_area_is_noop(): void
    {
        $table = new Table(['A']);

        $buf = Buffer::empty(3, 2);
        $table->render(Rect::sized(3, 2), $buf);

        self::assertSame(' ', $buf->get(0, 0)->char);
    }
}
