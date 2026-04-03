<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Input;

use Phalanx\Console\Input\NumberInput;
use PHPUnit\Framework\Attributes\Test;

final class NumberInputTest extends PromptTestCase
{
    private function intInput(int $default = 0, ?int $min = null, ?int $max = null): NumberInput
    {
        return new NumberInput(
            theme: $this->theme,
            label: 'Count',
            float: false,
            min: $min,
            max: $max,
            step: 1,
            placeholder: '',
            default: $default,
            hint: '',
            validate: null,
        );
    }

    private function floatInput(): NumberInput
    {
        return new NumberInput(
            theme: $this->theme,
            label: 'Price',
            float: true,
            min: null,
            max: null,
            step: 0.1,
            placeholder: '',
            default: 0,
            hint: '',
            validate: null,
        );
    }

    #[Test]
    public function submits_typed_integer(): void
    {
        $result = null;
        $this->intInput()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('4', '2', self::ENTER);

        self::assertSame(42, $result);
    }

    #[Test]
    public function filters_out_non_digit_characters(): void
    {
        $result = null;
        $this->intInput()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('1', 'a', 'b', '2', self::ENTER);

        self::assertSame(12, $result);
    }

    #[Test]
    public function up_arrow_increments_by_step(): void
    {
        $result = null;
        $this->intInput(default: 5)->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::UP, self::ENTER);

        self::assertSame(6, $result);
    }

    #[Test]
    public function down_arrow_decrements_by_step(): void
    {
        $result = null;
        $this->intInput(default: 5)->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::DOWN, self::ENTER);

        self::assertSame(4, $result);
    }

    #[Test]
    public function clamps_to_min_bound(): void
    {
        $result = null;
        $this->intInput(default: 1, min: 0)->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::DOWN, self::DOWN, self::DOWN, self::ENTER);

        self::assertSame(0, $result);
    }

    #[Test]
    public function clamps_to_max_bound(): void
    {
        $result = null;
        $this->intInput(default: 9, max: 10)->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::UP, self::UP, self::ENTER);

        self::assertSame(10, $result);
    }

    #[Test]
    public function accepts_float_with_decimal_point(): void
    {
        $result = null;
        $this->floatInput()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('3', '.', '1', '4', self::ENTER);

        self::assertEqualsWithDelta(3.14, $result, 0.0001);
    }

    #[Test]
    public function validation_blocks_submit_when_out_of_range(): void
    {
        $result = null;
        $this->intInput(min: 10)->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('5', self::ENTER);
        self::assertNull($result);

        // value is still '5' after failed submit; appending another '5' → '55' which passes
        $this->press('5', self::ENTER);
        self::assertSame(55, $result);
    }
}
