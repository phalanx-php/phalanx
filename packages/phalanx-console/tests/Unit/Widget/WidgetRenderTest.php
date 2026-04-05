<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Widget;

use Phalanx\Console\Style\Style;
use Phalanx\Console\Style\Theme;
use Phalanx\Console\Widget\Badge;
use Phalanx\Console\Widget\Box;
use Phalanx\Console\Widget\BoxStyle;
use Phalanx\Console\Widget\Divider;
use Phalanx\Console\Widget\KeyValue;
use Phalanx\Console\Widget\ProgressBar;
use Phalanx\Console\Widget\Spinner;
use Phalanx\Console\Widget\Table;
use Phalanx\Console\Widget\TaskList;
use Phalanx\Console\Widget\TaskState;
use Phalanx\Console\Widget\Tree;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WidgetRenderTest extends TestCase
{
    private Theme $theme;

    protected function setUp(): void
    {
        $plain = Style::new();
        $this->theme = new Theme(
            success: $plain,
            warning: $plain,
            error:   $plain,
            muted:   $plain,
            accent:  $plain,
            label:   $plain,
            hint:    $plain,
            border:  $plain,
            active:  $plain,
        );
    }

    #[Test]
    public function badge_wraps_label_with_padding(): void
    {
        self::assertSame(' OK ', Badge::render('OK', Style::new()));
    }

    #[Test]
    public function divider_without_label_fills_exact_width(): void
    {
        $out = Divider::render(20, $this->theme);
        self::assertSame(20, mb_strlen($out));
        self::assertStringContainsString('─', $out);
    }

    #[Test]
    public function divider_with_label_appears_in_output(): void
    {
        $out = Divider::render(30, $this->theme, 'Section');
        self::assertStringContainsString('Section', $out);
        self::assertMatchesRegularExpression('/─+\s*Section\s*─*/', $out);
    }

    #[Test]
    public function key_value_renders_all_pairs(): void
    {
        $out = KeyValue::render(['Freq' => '48.7 MHz', 'Mode' => 'QAM64'], $this->theme);
        self::assertStringContainsString('Freq', $out);
        self::assertStringContainsString('48.7 MHz', $out);
        self::assertStringContainsString('Mode', $out);
        self::assertStringContainsString('QAM64', $out);
    }

    #[Test]
    public function key_value_aligns_values_at_same_column(): void
    {
        $out   = KeyValue::render(['A' => 'val-one', 'LongerKey' => 'val-two'], $this->theme);
        $lines = explode("\n", $out);
        self::assertSame(mb_strpos($lines[0], 'val-one'), mb_strpos($lines[1], 'val-two'));
    }

    #[Test]
    public function key_value_returns_empty_string_for_empty_pairs(): void
    {
        self::assertSame('', KeyValue::render([], $this->theme));
    }

    #[Test]
    public function box_renders_rounded_border_with_content(): void
    {
        $out = Box::render('hello');
        self::assertStringContainsString('╭', $out);
        self::assertStringContainsString('╰', $out);
        self::assertStringContainsString('│', $out);
        self::assertStringContainsString('hello', $out);
    }

    #[Test]
    public function box_embeds_title_in_top_border_only(): void
    {
        $out   = Box::render('content', 'MyTitle');
        $lines = explode("\n", $out);
        self::assertStringContainsString('MyTitle', $lines[0]);
        self::assertStringNotContainsString('MyTitle', $lines[1]);
    }

    #[Test]
    public function box_top_border_matches_requested_width(): void
    {
        $out     = Box::render('hi', '', BoxStyle::Rounded, null, 30);
        $topLine = explode("\n", $out)[0];
        self::assertSame(30, mb_strlen($topLine));
    }

    #[Test]
    public function box_single_style_uses_square_corners(): void
    {
        $out = Box::render('x', '', BoxStyle::Single);
        self::assertStringContainsString('┌', $out);
        self::assertStringContainsString('┘', $out);
    }

    #[Test]
    public function box_multiline_content_preserves_all_lines(): void
    {
        $out = Box::render("line1\nline2\nline3");
        self::assertStringContainsString('line1', $out);
        self::assertStringContainsString('line2', $out);
        self::assertStringContainsString('line3', $out);
    }

    #[Test]
    public function progress_bar_shows_percentage_text(): void
    {
        $bar = new ProgressBar($this->theme);
        self::assertStringContainsString(' 50%', $bar->render(50, 100, 40));
        self::assertStringContainsString('100%', $bar->render(100, 100, 40));
        self::assertStringContainsString('  0%', $bar->render(0, 100, 40));
    }

    #[Test]
    public function progress_bar_includes_label(): void
    {
        $bar = new ProgressBar($this->theme);
        $out = $bar->render(25, 100, 60, 'Loading');
        self::assertStringContainsString('Loading', $out);
        self::assertStringContainsString(' 25%', $out);
    }

    #[Test]
    public function progress_bar_degrades_to_text_only_when_too_narrow(): void
    {
        $bar = new ProgressBar($this->theme);
        $out = $bar->render(75, 100, 5);
        self::assertStringContainsString(' 75%', $out);
        self::assertStringNotContainsString('█', $out);
    }

    #[Test]
    public function spinner_cycles_through_frames_and_wraps(): void
    {
        $spinner = new Spinner($this->theme, Spinner::LINE);
        $count   = count(Spinner::LINE);

        foreach (Spinner::LINE as $i => $char) {
            self::assertStringContainsString($char, $spinner->frame($i));
        }

        self::assertSame($spinner->frame(0), $spinner->frame($count));
    }

    #[Test]
    public function spinner_appends_label(): void
    {
        $out = new Spinner($this->theme)->frame(0, 'Working');
        self::assertStringContainsString('Working', $out);
    }

    #[Test]
    public function table_compute_widths_returns_one_entry_per_header(): void
    {
        $widths = Table::computeWidths(['IP', 'Status', 'FW'], [], 120);
        self::assertCount(3, $widths);
        foreach ($widths as $w) {
            self::assertGreaterThan(0, $w);
        }
    }

    #[Test]
    public function table_compute_widths_shrinks_columns_when_overflow(): void
    {
        $headers = [str_repeat('A', 60), str_repeat('B', 60)];
        $widths  = Table::computeWidths($headers, [], 80);
        self::assertLessThan(60, $widths[0]);
        self::assertLessThan(60, $widths[1]);
    }

    #[Test]
    public function table_header_contains_all_header_text_and_separator(): void
    {
        $table  = new Table($this->theme);
        $widths = [12, 8, 6];
        $out    = $table->header(['IP Address', 'Status', 'FW Ver'], $widths);
        self::assertStringContainsString('IP Address', $out);
        self::assertStringContainsString('Status', $out);
        self::assertStringContainsString('FW Ver', $out);
        self::assertStringContainsString('─', $out);
    }

    #[Test]
    public function table_row_truncates_overflowing_cell_with_tilde(): void
    {
        $table  = new Table($this->theme);
        $widths = [10, 6];
        $out    = $table->row(['192.168.1.100', 'OK'], $widths);
        self::assertStringContainsString('~', $out);
        self::assertStringContainsString('OK', $out);
    }

    #[Test]
    public function table_footer_includes_summary_text(): void
    {
        $out = new Table($this->theme)->footer([10, 6], 'Found 3 items');
        self::assertStringContainsString('Found 3 items', $out);
    }

    #[Test]
    public function tree_renders_connector_chars_for_flat_list(): void
    {
        $out = Tree::render(['alpha', 'beta', 'gamma'], $this->theme);
        self::assertStringContainsString('├─', $out);
        self::assertStringContainsString('└─', $out);
        self::assertStringContainsString('alpha', $out);
        self::assertStringContainsString('gamma', $out);
    }

    #[Test]
    public function tree_renders_nested_children_with_vertical_connector(): void
    {
        // parent is non-last (sibling follows), so children get │ prefix
        $out = Tree::render(['parent' => ['child1', 'child2'], 'sibling' => []], $this->theme);
        self::assertStringContainsString('parent', $out);
        self::assertStringContainsString('child1', $out);
        self::assertStringContainsString('child2', $out);
        self::assertStringContainsString('│', $out);
    }

    #[Test]
    public function tree_single_item_uses_corner_not_branch_connector(): void
    {
        $out = Tree::render(['only'], $this->theme);
        self::assertStringContainsString('└─', $out);
        self::assertStringNotContainsString('├─', $out);
    }

    #[Test]
    public function task_list_renders_state_icons_correctly(): void
    {
        $list = new TaskList($this->theme);
        $list->add('a', 'Step A');
        $list->add('b', 'Step B');
        $list->add('c', 'Step C');
        $list->setState('a', TaskState::Success);
        $list->setState('b', TaskState::Error, 'timeout');
        $list->setState('c', TaskState::Pending);

        $out = $list->render();
        self::assertStringContainsString('✓', $out);
        self::assertStringContainsString('✗', $out);
        self::assertStringContainsString('○', $out);
        self::assertStringContainsString('timeout', $out);
    }

    #[Test]
    public function task_list_ignores_unknown_id(): void
    {
        $list = new TaskList($this->theme);
        $list->setState('nonexistent', TaskState::Success);
        self::assertSame('', $list->render());
    }
}
