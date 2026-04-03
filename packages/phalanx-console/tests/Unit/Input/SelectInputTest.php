<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Input;

use Phalanx\Console\Input\SelectInput;
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
        $result = null;
        $this->select()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::ENTER);

        self::assertSame('a', $result);
    }

    #[Test]
    public function down_arrow_moves_to_next_option(): void
    {
        $result = null;
        $this->select()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::DOWN, self::ENTER);

        self::assertSame('b', $result);
    }

    #[Test]
    public function j_key_also_moves_down(): void
    {
        $result = null;
        $this->select()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('j', 'j', self::ENTER);

        self::assertSame('c', $result);
    }

    #[Test]
    public function up_arrow_does_not_go_below_zero(): void
    {
        $result = null;
        $this->select()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::UP, self::UP, self::ENTER);

        self::assertSame('a', $result);
    }

    #[Test]
    public function end_key_jumps_to_last_option(): void
    {
        $result = null;
        $this->select()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press("\x1b[F", self::ENTER); // End key = ESC [ F

        self::assertSame('c', $result);
    }

    #[Test]
    public function non_tty_returns_first_option(): void
    {
        $noTty  = new \Phalanx\Console\Input\RawInput(isTty: false);
        $result = null;
        $this->select(['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma'])->prompt($this->output, $noTty)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        self::assertSame('a', $result);
    }
}
