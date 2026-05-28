<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Slice\SettingsSlice;
use Phalanx\Theatron\Demos\Repl\Slice\SettingsTab;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingsSliceTest extends TestCase
{
    #[Test]
    public function slice_key_is_repl_settings(): void
    {
        self::assertSame('repl.settings', (new SettingsSlice())->key);
    }

    #[Test]
    public function select_tab_changes_tab_and_resets_item(): void
    {
        $slice = new SettingsSlice(activeTab: SettingsTab::General, selectedItem: 5);

        $updated = $slice->selectTab(SettingsTab::Tools);

        self::assertSame(SettingsTab::Tools, $updated->activeTab);
        self::assertSame(0, $updated->selectedItem);
    }

    #[Test]
    public function select_tab_is_immutable(): void
    {
        $slice = new SettingsSlice(activeTab: SettingsTab::General);

        $slice->selectTab(SettingsTab::Model);

        self::assertSame(SettingsTab::General, $slice->activeTab);
    }

    #[Test]
    public function next_tab_advances_and_wraps(): void
    {
        $tabs = SettingsTab::cases();
        $lastTab = $tabs[count($tabs) - 1];
        $slice = new SettingsSlice(activeTab: $lastTab);

        $updated = $slice->nextTab();

        self::assertSame($tabs[0], $updated->activeTab);
        self::assertSame(0, $updated->selectedItem);
    }

    #[Test]
    public function next_tab_advances_normally(): void
    {
        $slice = new SettingsSlice(activeTab: SettingsTab::General);

        $updated = $slice->nextTab();

        self::assertSame(SettingsTab::Tools, $updated->activeTab);
    }

    #[Test]
    public function prev_tab_goes_backward_and_wraps(): void
    {
        $tabs = SettingsTab::cases();
        $slice = new SettingsSlice(activeTab: $tabs[0]);

        $updated = $slice->prevTab();

        self::assertSame($tabs[count($tabs) - 1], $updated->activeTab);
        self::assertSame(0, $updated->selectedItem);
    }

    #[Test]
    public function prev_tab_decrements_normally(): void
    {
        $slice = new SettingsSlice(activeTab: SettingsTab::Tools);

        $updated = $slice->prevTab();

        self::assertSame(SettingsTab::General, $updated->activeTab);
    }

    #[Test]
    public function next_item_increments(): void
    {
        $slice = new SettingsSlice(selectedItem: 2);

        $updated = $slice->nextItem(10);

        self::assertSame(3, $updated->selectedItem);
    }

    #[Test]
    public function next_item_clamps_at_max(): void
    {
        $slice = new SettingsSlice(selectedItem: 3);

        $clamped = $slice->nextItem(4);

        self::assertSame($slice, $clamped);
    }

    #[Test]
    public function next_item_is_immutable(): void
    {
        $slice = new SettingsSlice(selectedItem: 0);

        $slice->nextItem(10);

        self::assertSame(0, $slice->selectedItem);
    }

    #[Test]
    public function prev_item_decrements(): void
    {
        $slice = new SettingsSlice(selectedItem: 3);

        $updated = $slice->prevItem();

        self::assertSame(2, $updated->selectedItem);
    }

    #[Test]
    public function prev_item_returns_same_at_zero(): void
    {
        $slice = new SettingsSlice(selectedItem: 0);

        $result = $slice->prevItem();

        self::assertSame($slice, $result);
    }
}
