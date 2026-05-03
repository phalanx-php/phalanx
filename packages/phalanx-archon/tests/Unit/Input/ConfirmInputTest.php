<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Input;

use Phalanx\Archon\Input\ConfirmInput;
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
        $reader = $this->reader(['y']);

        $result = $this->confirm()->prompt($this->scope, $this->output, $reader);

        self::assertTrue($result);
    }

    #[Test]
    public function n_key_submits_false(): void
    {
        $reader = $this->reader(['n']);

        $result = $this->confirm()->prompt($this->scope, $this->output, $reader);

        self::assertFalse($result);
    }

    #[Test]
    public function enter_submits_default_true(): void
    {
        $reader = $this->reader([self::ENTER]);

        $result = $this->confirm(default: true)->prompt($this->scope, $this->output, $reader);

        self::assertTrue($result);
    }

    #[Test]
    public function enter_submits_default_false(): void
    {
        $reader = $this->reader([self::ENTER]);

        $result = $this->confirm(default: false)->prompt($this->scope, $this->output, $reader);

        self::assertFalse($result);
    }

    #[Test]
    public function tab_toggles_selection(): void
    {
        $reader = $this->reader([self::TAB, self::ENTER]);

        $result = $this->confirm(default: true)->prompt($this->scope, $this->output, $reader);

        self::assertFalse($result);
    }

    #[Test]
    public function right_arrow_selects_no(): void
    {
        $reader = $this->reader([self::RIGHT, self::ENTER]);

        $result = $this->confirm(default: true)->prompt($this->scope, $this->output, $reader);

        self::assertFalse($result);
    }
}
