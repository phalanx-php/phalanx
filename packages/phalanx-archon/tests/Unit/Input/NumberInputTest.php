<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Input;

use Phalanx\Archon\Input\NumberInput;
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
        $reader = $this->reader(['4', '2', self::ENTER]);

        $result = $this->intInput()->prompt($this->scope, $this->output, $reader);

        self::assertSame(42, $result);
    }

    #[Test]
    public function filters_out_non_digit_characters(): void
    {
        $reader = $this->reader(['1', 'a', 'b', '2', self::ENTER]);

        $result = $this->intInput()->prompt($this->scope, $this->output, $reader);

        self::assertSame(12, $result);
    }

    #[Test]
    public function up_arrow_increments_by_step(): void
    {
        $reader = $this->reader([self::UP, self::ENTER]);

        $result = $this->intInput(default: 5)->prompt($this->scope, $this->output, $reader);

        self::assertSame(6, $result);
    }

    #[Test]
    public function down_arrow_decrements_by_step(): void
    {
        $reader = $this->reader([self::DOWN, self::ENTER]);

        $result = $this->intInput(default: 5)->prompt($this->scope, $this->output, $reader);

        self::assertSame(4, $result);
    }

    #[Test]
    public function clamps_to_min_bound(): void
    {
        $reader = $this->reader([self::DOWN, self::DOWN, self::DOWN, self::ENTER]);

        $result = $this->intInput(default: 1, min: 0)->prompt($this->scope, $this->output, $reader);

        self::assertSame(0, $result);
    }

    #[Test]
    public function clamps_to_max_bound(): void
    {
        $reader = $this->reader([self::UP, self::UP, self::ENTER]);

        $result = $this->intInput(default: 9, max: 10)->prompt($this->scope, $this->output, $reader);

        self::assertSame(10, $result);
    }

    #[Test]
    public function accepts_float_with_decimal_point(): void
    {
        $reader = $this->reader(['3', '.', '1', '4', self::ENTER]);

        $result = $this->floatInput()->prompt($this->scope, $this->output, $reader);

        self::assertEqualsWithDelta(3.14, $result, 0.0001);
    }

    #[Test]
    public function validation_blocks_submit_when_out_of_range(): void
    {
        $reader = $this->reader(['5', self::ENTER, '5', self::ENTER]);

        $result = $this->intInput(min: 10)->prompt($this->scope, $this->output, $reader);

        self::assertSame(55, $result);
    }
}
