<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Input;

use Phalanx\Console\Input\CancelledException;
use Phalanx\Console\Input\TextInput;
use PHPUnit\Framework\Attributes\Test;

final class TextInputTest extends PromptTestCase
{
    private function input(string $label = 'Name', string $default = '', ?callable $validate = null): TextInput
    {
        return new TextInput(
            theme: $this->theme,
            label: $label,
            placeholder: '',
            default: $default,
            hint: '',
            validate: $validate !== null ? \Closure::fromCallable($validate) : null,
            transform: null,
        );
    }

    #[Test]
    public function submits_typed_value_on_enter(): void
    {
        $result = null;
        $this->input()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('h', 'e', 'l', 'l', 'o', self::ENTER);

        self::assertSame('hello', $result);
    }

    #[Test]
    public function returns_default_when_nothing_typed(): void
    {
        $result = null;
        $this->input(default: 'fallback')->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press(self::ENTER);

        self::assertSame('fallback', $result);
    }

    #[Test]
    public function backspace_removes_last_character(): void
    {
        $result = null;
        $this->input()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('a', 'b', 'c', self::BACKSPACE, self::ENTER);

        self::assertSame('ab', $result);
    }

    #[Test]
    public function cancel_via_ctrl_c_rejects_promise(): void
    {
        $rejected = null;
        $this->input()->prompt($this->output, $this->input)
            ->then(null, static function ($e) use (&$rejected): void {
                $rejected = $e;
            });

        $this->press('h', 'i', self::CTRL_C);

        self::assertInstanceOf(CancelledException::class, $rejected);
    }

    #[Test]
    public function validation_error_blocks_submit_until_corrected(): void
    {
        $callCount = 0;
        $result    = null;

        $prompt = $this->input(validate: static function (string $v) use (&$callCount): ?string {
            $callCount++;
            return mb_strlen($v) < 3 ? 'Too short' : null;
        });

        $prompt->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        // 'ab' fails validation — prompt stays open
        $this->press('a', 'b', self::ENTER);
        self::assertNull($result);

        // 'abc' passes
        $this->press('c', self::ENTER);
        self::assertSame('abc', $result);
    }

    #[Test]
    public function non_tty_returns_default_immediately(): void
    {
        $prompt = new TextInput(theme: $this->theme, label: 'X', placeholder: '', default: 'skip', hint: '', validate: null, transform: null);
        $noTty  = new \Phalanx\Console\Input\RawInput(isTty: false);

        $result = null;
        $prompt->prompt($this->output, $noTty)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        self::assertSame('skip', $result);
    }
}
