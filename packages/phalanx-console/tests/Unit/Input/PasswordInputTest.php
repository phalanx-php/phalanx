<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Input;

use Phalanx\Console\Input\PasswordInput;
use PHPUnit\Framework\Attributes\Test;

final class PasswordInputTest extends PromptTestCase
{
    private function password(?callable $validate = null): PasswordInput
    {
        return new PasswordInput(
            theme: $this->theme,
            label: 'Password',
            hint: '',
            validate: $validate !== null ? \Closure::fromCallable($validate) : null,
        );
    }

    #[Test]
    public function submits_typed_password_as_plain_text(): void
    {
        $result = null;
        $this->password()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('s', 'e', 'c', 'r', 'e', 't', self::ENTER);

        self::assertSame('secret', $result);
    }

    #[Test]
    public function answered_output_does_not_contain_actual_password(): void
    {
        $this->password()->prompt($this->output, $this->input);

        $this->press('m', 'y', 'p', 'a', 's', 's', self::ENTER);

        rewind($this->stream);
        $written = stream_get_contents($this->stream);

        self::assertStringNotContainsString('mypass', $written);
        self::assertStringContainsString('•', $written);
    }

    #[Test]
    public function backspace_removes_character_from_value(): void
    {
        $result = null;
        $this->password()->prompt($this->output, $this->input)->then(static function ($v) use (&$result): void {
            $result = $v;
        });

        $this->press('a', 'b', 'c', self::BACKSPACE, self::ENTER);

        self::assertSame('ab', $result);
    }

    #[Test]
    public function validation_applies_to_password_value(): void
    {
        $result = null;
        $this->password(validate: static fn(string $v): ?string => mb_strlen($v) < 4 ? 'Too short' : null)
            ->prompt($this->output, $this->input)
            ->then(static function ($v) use (&$result): void { $result = $v; });

        $this->press('a', 'b', 'c', self::ENTER);
        self::assertNull($result);

        $this->press('d', self::ENTER);
        self::assertSame('abcd', $result);
    }
}
