<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Slice\SettingsTab;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingsTabTest extends TestCase
{
    #[Test]
    public function every_tab_has_at_least_one_item(): void
    {
        foreach (SettingsTab::cases() as $tab) {
            self::assertGreaterThan(0, $tab->itemCount(), "Tab {$tab->value} has no items");
        }
    }

    #[Test]
    public function item_count_matches_items_array(): void
    {
        foreach (SettingsTab::cases() as $tab) {
            self::assertSame(count($tab->items()), $tab->itemCount(), "Tab {$tab->value} count mismatch");
        }
    }

    #[Test]
    public function general_tab_has_four_items(): void
    {
        self::assertSame(4, SettingsTab::General->itemCount());
    }
}
