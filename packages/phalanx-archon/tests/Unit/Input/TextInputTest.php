<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Input;

use Closure;
use Phalanx\Archon\Input\CancelledException;
use Phalanx\Archon\Input\TextInput;
use PHPUnit\Framework\Attributes\Test;

final class TextInputTest extends PromptTestCase
{
    private function input(string $label = 'Name', string $default = '', ?Closure $validate = null): TextInput
    {
        return new TextInput(
            theme: $this->theme,
            label: $label,
            placeholder: '',
            default: $default,
            hint: '',
            validate: $validate,
            transform: null,
        );
    }

    #[Test]
    public function submits_typed_value_on_enter(): void
    {
        $reader = $this->reader(['h', 'e', 'l', 'l', 'o', self::ENTER]);

        $result = $this->input()->prompt($this->scope, $this->output, $reader);

        self::assertSame('hello', $result);
    }

    #[Test]
    public function returns_default_when_nothing_typed(): void
    {
        $reader = $this->reader([self::ENTER]);

        $result = $this->input(default: 'fallback')->prompt($this->scope, $this->output, $reader);

        self::assertSame('fallback', $result);
    }

    #[Test]
    public function backspace_removes_last_character(): void
    {
        $reader = $this->reader(['a', 'b', 'c', self::BACKSPACE, self::ENTER]);

        $result = $this->input()->prompt($this->scope, $this->output, $reader);

        self::assertSame('ab', $result);
    }

    #[Test]
    public function cancel_via_ctrl_c_throws(): void
    {
        $reader = $this->reader(['h', 'i', self::CTRL_C]);

        $this->expectException(CancelledException::class);

        $this->input()->prompt($this->scope, $this->output, $reader);
    }

    #[Test]
    public function validation_error_blocks_submit_until_corrected(): void
    {
        $callCount = 0;
        $reader    = $this->reader(['a', 'b', self::ENTER, 'c', self::ENTER]);

        $prompt = $this->input(validate: static function (string $v) use (&$callCount): ?string {
            $callCount++;
            return mb_strlen($v) < 3 ? 'Too short' : null;
        });

        $result = $prompt->prompt($this->scope, $this->output, $reader);

        self::assertSame('abc', $result);
        self::assertGreaterThan(1, $callCount);
    }

    #[Test]
    public function non_tty_returns_default_immediately(): void
    {
        $prompt = new TextInput(theme: $this->theme, label: 'X', placeholder: '', default: 'skip', hint: '', validate: null, transform: null);
        $reader = $this->reader([], interactive: false);

        $result = $prompt->prompt($this->scope, $this->output, $reader);

        self::assertSame('skip', $result);
    }
}
