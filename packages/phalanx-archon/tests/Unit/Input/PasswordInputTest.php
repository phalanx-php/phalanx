<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Input;

use Closure;
use Phalanx\Archon\Input\PasswordInput;
use PHPUnit\Framework\Attributes\Test;

final class PasswordInputTest extends PromptTestCase
{
    private function password(?Closure $validate = null): PasswordInput
    {
        return new PasswordInput(
            theme: $this->theme,
            label: 'Password',
            hint: '',
            validate: $validate,
        );
    }

    #[Test]
    public function submits_typed_password_as_plain_text(): void
    {
        $reader = $this->reader(['s', 'e', 'c', 'r', 'e', 't', self::ENTER]);

        $result = $this->password()->prompt($this->scope, $this->output, $reader);

        self::assertSame('secret', $result);
    }

    #[Test]
    public function answered_output_does_not_contain_actual_password(): void
    {
        $reader = $this->reader(['m', 'y', 'p', 'a', 's', 's', self::ENTER]);

        $this->password()->prompt($this->scope, $this->output, $reader);

        rewind($this->stream);
        $written = stream_get_contents($this->stream);
        self::assertIsString($written);

        self::assertStringNotContainsString('mypass', $written);
        self::assertStringContainsString('•', $written);
    }

    #[Test]
    public function backspace_removes_character_from_value(): void
    {
        $reader = $this->reader(['a', 'b', 'c', self::BACKSPACE, self::ENTER]);

        $result = $this->password()->prompt($this->scope, $this->output, $reader);

        self::assertSame('ab', $result);
    }

    #[Test]
    public function validation_applies_to_password_value(): void
    {
        $reader = $this->reader(['a', 'b', 'c', self::ENTER, 'd', self::ENTER]);

        $result = $this->password(
            validate: static fn(string $v): ?string => mb_strlen($v) < 4 ? 'Too short' : null,
        )->prompt($this->scope, $this->output, $reader);

        self::assertSame('abcd', $result);
    }
}
