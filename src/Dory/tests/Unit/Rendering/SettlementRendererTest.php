<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit\Rendering;

use Phalanx\Concurrency\Settlement;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Dory\Rendering\SettlementRenderer;
use Phalanx\Dory\Tests\Fixtures\BufferSink;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettlementRendererTest extends TestCase
{
    #[Test]
    public function supports_settlement_bag(): void
    {
        $renderer = new SettlementRenderer();
        $bag = new SettlementBag(['a' => Settlement::ok('olympus')]);

        self::assertTrue($renderer->supports($bag));
    }

    #[Test]
    public function does_not_support_non_settlement_bag(): void
    {
        $renderer = new SettlementRenderer();

        self::assertFalse($renderer->supports('not a bag'));
        self::assertFalse($renderer->supports(42));
        self::assertFalse($renderer->supports(new \stdClass()));
    }

    #[Test]
    public function renders_all_ok_summary(): void
    {
        $renderer = new SettlementRenderer();
        $sink = new BufferSink();

        $bag = new SettlementBag([
            'alpha' => Settlement::ok('done'),
            'beta' => Settlement::ok('complete'),
        ]);

        $renderer->render($bag, $sink);

        self::assertSame('all 2 succeeded', $sink->lines[0]);
    }

    #[Test]
    public function renders_all_err_summary(): void
    {
        $renderer = new SettlementRenderer();
        $sink = new BufferSink();

        $bag = new SettlementBag([
            'alpha' => Settlement::err(new \RuntimeException('failed alpha')),
            'beta' => Settlement::err(new \RuntimeException('failed beta')),
        ]);

        $renderer->render($bag, $sink);

        self::assertSame('all 2 failed', $sink->lines[0]);
    }

    #[Test]
    public function renders_mixed_summary(): void
    {
        $renderer = new SettlementRenderer();
        $sink = new BufferSink();

        $bag = new SettlementBag([
            'alpha' => Settlement::ok('victory'),
            'beta' => Settlement::err(new \RuntimeException('retreat')),
            'gamma' => Settlement::ok('hold'),
        ]);

        $renderer->render($bag, $sink);

        self::assertSame('2/3 succeeded', $sink->lines[0]);
    }

    #[Test]
    public function renders_ok_item_status_line(): void
    {
        $renderer = new SettlementRenderer();
        $sink = new BufferSink();

        $bag = new SettlementBag([
            'deploy' => Settlement::ok('marathon'),
        ]);

        $renderer->render($bag, $sink);

        self::assertCount(2, $sink->lines);
        self::assertSame('  [deploy] ok: marathon', $sink->lines[1]);
    }

    #[Test]
    public function renders_err_item_status_line(): void
    {
        $renderer = new SettlementRenderer();
        $sink = new BufferSink();

        $bag = new SettlementBag([
            'siege' => Settlement::err(new \RuntimeException('walls held')),
        ]);

        $renderer->render($bag, $sink);

        self::assertCount(2, $sink->lines);
        self::assertSame('  [siege] err: walls held', $sink->lines[1]);
    }

    #[Test]
    public function renders_non_scalar_ok_value_via_var_export(): void
    {
        $renderer = new SettlementRenderer();
        $sink = new BufferSink();

        $bag = new SettlementBag([
            'council' => Settlement::ok(['zeus', 'hera']),
        ]);

        $renderer->render($bag, $sink);

        self::assertCount(2, $sink->lines);
        self::assertStringContainsString('[council] ok:', $sink->lines[1]);
        self::assertStringContainsString('zeus', $sink->lines[1]);
    }

    #[Test]
    public function renders_per_item_lines_in_order(): void
    {
        $renderer = new SettlementRenderer();
        $sink = new BufferSink();

        $bag = new SettlementBag([
            'first' => Settlement::ok('pericles'),
            'second' => Settlement::err(new \RuntimeException('lost')),
            'third' => Settlement::ok('aristotle'),
        ]);

        $renderer->render($bag, $sink);

        self::assertCount(4, $sink->lines);
        self::assertStringContainsString('[first] ok: pericles', $sink->lines[1]);
        self::assertStringContainsString('[second] err: lost', $sink->lines[2]);
        self::assertStringContainsString('[third] ok: aristotle', $sink->lines[3]);
    }
}
