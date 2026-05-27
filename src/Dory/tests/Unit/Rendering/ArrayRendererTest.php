<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit\Rendering;

use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\Table;
use Phalanx\Dory\Rendering\ArrayRenderer;
use Phalanx\Dory\Tests\Fixtures\BufferSink;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArrayRendererTest extends TestCase
{
    #[Test]
    public function supports_non_empty_array(): void
    {
        $renderer = new ArrayRenderer(new Table(Theme::default()));

        self::assertTrue($renderer->supports(['leonidas']));
    }

    #[Test]
    public function does_not_support_empty_array(): void
    {
        $renderer = new ArrayRenderer(new Table(Theme::default()));

        self::assertFalse($renderer->supports([]));
    }

    #[Test]
    public function does_not_support_string(): void
    {
        $renderer = new ArrayRenderer(new Table(Theme::default()));

        self::assertFalse($renderer->supports('phalanx'));
    }

    #[Test]
    public function renders_sequential_list(): void
    {
        $renderer = new ArrayRenderer(new Table(Theme::default()));
        $sink = new BufferSink();

        $renderer->render(['alpha', 'beta', 'gamma'], $sink);

        self::assertCount(3, $sink->lines);
        self::assertStringContainsString('[0] alpha', $sink->lines[0]);
        self::assertStringContainsString('[1] beta', $sink->lines[1]);
        self::assertStringContainsString('[2] gamma', $sink->lines[2]);
    }

    #[Test]
    public function renders_associative_array_as_key_value(): void
    {
        $renderer = new ArrayRenderer(new Table(Theme::default()));
        $sink = new BufferSink();

        $renderer->render(['city' => 'Sparta', 'warriors' => '300'], $sink);

        self::assertCount(2, $sink->lines);
        self::assertStringContainsString('city: Sparta', $sink->lines[0]);
        self::assertStringContainsString('warriors: 300', $sink->lines[1]);
    }

    #[Test]
    public function renders_tabular_data_with_table_widget(): void
    {
        $renderer = new ArrayRenderer(new Table(Theme::default()));
        $sink = new BufferSink();

        $data = [
            ['name' => 'Leonidas', 'role' => 'King'],
            ['name' => 'Themistocles', 'role' => 'Strategos'],
        ];

        $renderer->render($data, $sink);

        $joined = implode("\n", $sink->lines);
        self::assertStringContainsString('Leonidas', $joined);
        self::assertStringContainsString('Themistocles', $joined);
        self::assertStringContainsString('King', $joined);
        self::assertStringContainsString('Strategos', $joined);
    }

    #[Test]
    public function tabular_output_includes_header_row_and_data_rows(): void
    {
        $renderer = new ArrayRenderer(new Table(Theme::default()));
        $sink = new BufferSink();

        $data = [
            ['polis' => 'Athens', 'strength' => '500'],
            ['polis' => 'Sparta', 'strength' => '300'],
        ];

        $renderer->render($data, $sink);

        self::assertGreaterThanOrEqual(4, count($sink->lines));

        $headerLine = $sink->lines[0];
        self::assertStringContainsString('polis', $headerLine);
        self::assertStringContainsString('strength', $headerLine);
    }

    #[Test]
    public function list_with_non_scalar_values_uses_var_export(): void
    {
        $renderer = new ArrayRenderer(new Table(Theme::default()));
        $sink = new BufferSink();

        $renderer->render([['nested']], $sink);

        $joined = implode("\n", $sink->lines);
        self::assertStringContainsString('[0]', $joined);
        self::assertStringContainsString('array', $joined);
    }

    #[Test]
    public function key_value_with_non_scalar_values_uses_var_export(): void
    {
        $renderer = new ArrayRenderer(new Table(Theme::default()));
        $sink = new BufferSink();

        $renderer->render(['data' => new \stdClass()], $sink);

        $joined = implode("\n", $sink->lines);
        self::assertStringContainsString('data:', $joined);
    }
}
