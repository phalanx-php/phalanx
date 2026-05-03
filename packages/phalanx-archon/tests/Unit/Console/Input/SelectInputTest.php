<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Input;

use Phalanx\Archon\Console\Input\SelectInput;
use PHPUnit\Framework\Attributes\Test;

final class SelectInputTest extends PromptTestCase
{
    /** @param array<string, string> $options */
    private function select(array $options = ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma']): SelectInput
    {
        return new SelectInput(theme: $this->theme, label: 'Choose', options: $options, scroll: 5);
    }

    #[Test]
    public function enter_submits_first_option_by_default(): void
    {
        $reader = $this->reader([self::ENTER]);

        $result = $this->select()->prompt($this->scope, $this->output, $reader);

        self::assertSame('a', $result);
    }

    #[Test]
    public function down_arrow_moves_to_next_option(): void
    {
        $reader = $this->reader([self::DOWN, self::ENTER]);

        $result = $this->select()->prompt($this->scope, $this->output, $reader);

        self::assertSame('b', $result);
    }

    #[Test]
    public function j_key_also_moves_down(): void
    {
        $reader = $this->reader(['j', 'j', self::ENTER]);

        $result = $this->select()->prompt($this->scope, $this->output, $reader);

        self::assertSame('c', $result);
    }

    #[Test]
    public function up_arrow_does_not_go_below_zero(): void
    {
        $reader = $this->reader([self::UP, self::UP, self::ENTER]);

        $result = $this->select()->prompt($this->scope, $this->output, $reader);

        self::assertSame('a', $result);
    }

    #[Test]
    public function end_key_jumps_to_last_option(): void
    {
        $reader = $this->reader(['end', self::ENTER]);

        $result = $this->select()->prompt($this->scope, $this->output, $reader);

        self::assertSame('c', $result);
    }

    #[Test]
    public function non_tty_returns_first_option(): void
    {
        $reader = $this->reader([], interactive: false);

        $result = $this->select()->prompt($this->scope, $this->output, $reader);

        self::assertSame('a', $result);
    }
}
