<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Input;

use Phalanx\Archon\Console\Input\TextInput;
use PHPUnit\Framework\Attributes\Test;

final class BasePromptValidateTest extends PromptTestCase
{
    #[Test]
    public function failedSubmitEntersErrorAndSubsequentKeystrokeSilentlyRevalidates(): void
    {
        $calls    = [];
        $validate = static function (mixed $value) use (&$calls): ?string {
            $calls[] = $value;
            return is_string($value) && mb_strlen($value) >= 3 ? null : 'min 3';
        };

        $prompt = new TextInput(
            theme: $this->theme,
            label: 'Name',
            placeholder: '',
            default: '',
            hint: '',
            validate: $validate,
        );

        $reader = $this->reader(['a', self::ENTER, 'b', 'c', self::ENTER]);

        $result = $prompt->prompt($this->scope, $this->output, $reader);

        self::assertSame('abc', $result);
        self::assertContains('a', $calls);
        self::assertContains('ab', $calls);
        self::assertContains('abc', $calls);
    }

    #[Test]
    public function silentRevalidateClearsErrorWhenValueBecomesValid(): void
    {
        $calls    = [];
        $validate = static function (mixed $value) use (&$calls): ?string {
            $message = is_string($value) && $value !== '' ? null : 'required';
            $calls[] = ['value' => $value, 'message' => $message];
            return $message;
        };

        $prompt = new TextInput(
            theme: $this->theme,
            label: 'Name',
            placeholder: '',
            default: '',
            hint: '',
            validate: $validate,
        );

        $reader = $this->reader([self::ENTER, 'x', self::ENTER]);

        $result = $prompt->prompt($this->scope, $this->output, $reader);

        self::assertSame('x', $result);

        $messages   = array_column($calls, 'message');
        $errorIndex = array_search('required', $messages, true);
        $clearIndex = array_search(null, $messages, true);
        self::assertNotFalse($errorIndex);
        self::assertNotFalse($clearIndex);
        self::assertGreaterThan($errorIndex, $clearIndex);
    }

    #[Test]
    public function silentRevalidateDoesNotPrematurelySubmit(): void
    {
        $validate = static fn(mixed $value): ?string
            => is_string($value) && mb_strlen($value) >= 3 ? null : 'min 3';

        $prompt = new TextInput(
            theme: $this->theme,
            label: 'Name',
            placeholder: '',
            default: '',
            hint: '',
            validate: $validate,
        );

        $reader = $this->reader(['a', self::ENTER, 'b', 'c', 'd', self::ENTER]);

        $result = $prompt->prompt($this->scope, $this->output, $reader);

        self::assertSame('abcd', $result);
    }
}
