<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Input;

use Phalanx\Console\Input\MultiSelectInput;
use PHPUnit\Framework\Attributes\Test;

final class MultiSelectInputTest extends PromptTestCase
{
    /** @param list<string> $defaults */
    private function multi(array $defaults = []): MultiSelectInput
    {
        return new MultiSelectInput(
            theme: $this->theme,
            label: 'Pick',
            options: ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma'],
            defaultValues: $defaults,
            scroll: 5,
        );
    }

    #[Test]
    public function enter_with_no_selection_returns_empty_array(): void
    {
        $result = null;
        $this->multi()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::ENTER);

        self::assertSame([], $result);
    }

    #[Test]
    public function space_toggles_highlighted_item(): void
    {
        $result = null;
        $this->multi()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::SPACE, self::ENTER);

        self::assertSame(['a'], $result);
    }

    #[Test]
    public function navigate_and_select_multiple(): void
    {
        $result = null;
        $this->multi()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::SPACE, self::DOWN, self::DOWN, self::SPACE, self::ENTER);

        self::assertEqualsCanonicalizing(['a', 'c'], $result);
    }

    #[Test]
    public function space_toggles_off_already_selected_item(): void
    {
        $result = null;
        $this->multi(defaults: ['a'])->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::SPACE, self::ENTER);

        self::assertSame([], $result);
    }

    #[Test]
    public function ctrl_a_selects_all(): void
    {
        $result = null;
        $this->multi()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::CTRL_A, self::ENTER);

        self::assertEqualsCanonicalizing(['a', 'b', 'c'], $result);
    }

    #[Test]
    public function ctrl_a_deselects_all_when_all_selected(): void
    {
        $result = null;
        $this->multi(defaults: ['a', 'b', 'c'])->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::CTRL_A, self::ENTER);

        self::assertSame([], $result);
    }

    #[Test]
    public function default_values_are_pre_selected(): void
    {
        $result = null;
        $this->multi(defaults: ['b'])->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::ENTER);

        self::assertSame(['b'], $result);
    }
}
