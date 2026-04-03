<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Input;

use Phalanx\Console\Input\ConfirmInput;
use PHPUnit\Framework\Attributes\Test;

final class ConfirmInputTest extends PromptTestCase
{
    private function confirm(bool $default = true): ConfirmInput
    {
        return new ConfirmInput(theme: $this->theme, label: 'Continue?', default: $default);
    }

    #[Test]
    public function y_key_submits_true(): void
    {
        $result = null;
        $this->confirm()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('y');

        self::assertTrue($result);
    }

    #[Test]
    public function n_key_submits_false(): void
    {
        $result = null;
        $this->confirm()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('n');

        self::assertFalse($result);
    }

    #[Test]
    public function enter_submits_default_true(): void
    {
        $result = null;
        $this->confirm(default: true)->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::ENTER);

        self::assertTrue($result);
    }

    #[Test]
    public function enter_submits_default_false(): void
    {
        $result = null;
        $this->confirm(default: false)->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::ENTER);

        self::assertFalse($result);
    }

    #[Test]
    public function tab_toggles_selection(): void
    {
        $result = null;
        $this->confirm(default: true)->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::TAB, self::ENTER);

        self::assertFalse($result);
    }

    #[Test]
    public function right_arrow_selects_no(): void
    {
        $result = null;
        $this->confirm(default: true)->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::RIGHT, self::ENTER);

        self::assertFalse($result);
    }
}
