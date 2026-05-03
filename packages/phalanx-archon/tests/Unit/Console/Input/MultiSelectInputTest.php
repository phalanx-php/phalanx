<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Input;

use Phalanx\Archon\Console\Input\MultiSelectInput;
use PHPUnit\Framework\Attributes\Test;

final class MultiSelectInputTest extends PromptTestCase
{
    private const string CTRL_A = 'ctrl-a';

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
        $reader = $this->reader([self::ENTER]);

        $result = $this->multi()->prompt($this->scope, $this->output, $reader);

        self::assertSame([], $result);
    }

    #[Test]
    public function space_toggles_highlighted_item(): void
    {
        $reader = $this->reader([self::SPACE, self::ENTER]);

        $result = $this->multi()->prompt($this->scope, $this->output, $reader);

        self::assertSame(['a'], $result);
    }

    #[Test]
    public function navigate_and_select_multiple(): void
    {
        $reader = $this->reader([self::SPACE, self::DOWN, self::DOWN, self::SPACE, self::ENTER]);

        $result = $this->multi()->prompt($this->scope, $this->output, $reader);

        self::assertEqualsCanonicalizing(['a', 'c'], $result);
    }

    #[Test]
    public function space_toggles_off_already_selected_item(): void
    {
        $reader = $this->reader([self::SPACE, self::ENTER]);

        $result = $this->multi(defaults: ['a'])->prompt($this->scope, $this->output, $reader);

        self::assertSame([], $result);
    }

    #[Test]
    public function ctrl_a_selects_all(): void
    {
        $reader = $this->reader([self::CTRL_A, self::ENTER]);

        $result = $this->multi()->prompt($this->scope, $this->output, $reader);

        self::assertEqualsCanonicalizing(['a', 'b', 'c'], $result);
    }

    #[Test]
    public function ctrl_a_deselects_all_when_all_selected(): void
    {
        $reader = $this->reader([self::CTRL_A, self::ENTER]);

        $result = $this->multi(defaults: ['a', 'b', 'c'])->prompt($this->scope, $this->output, $reader);

        self::assertSame([], $result);
    }

    #[Test]
    public function default_values_are_pre_selected(): void
    {
        $reader = $this->reader([self::ENTER]);

        $result = $this->multi(defaults: ['b'])->prompt($this->scope, $this->output, $reader);

        self::assertSame(['b'], $result);
    }
}
