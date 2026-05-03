<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Input;

use Closure;
use Phalanx\Archon\Console\Input\SuggestInput;
use PHPUnit\Framework\Attributes\Test;

final class SuggestInputTest extends PromptTestCase
{
    private function suggest(Closure $closure): SuggestInput
    {
        return new SuggestInput(
            theme: $this->theme,
            label: 'Suggest',
            search: $closure,
            placeholder: 'Type…',
            hint: '',
        );
    }

    #[Test]
    public function tabAcceptsHighlightedSuggestion(): void
    {
        $closure = static fn(string $q): array => $q === '' ? [] : ['alpha', 'alphabet'];

        $reader = $this->reader(['a', self::TAB, self::ENTER]);

        $result = $this->suggest($closure)->prompt($this->scope, $this->output, $reader);

        self::assertSame('alpha', $result);
    }

    #[Test]
    public function enterSubmitsTypedValueWithoutAcceptingSuggestion(): void
    {
        $closure = static fn(string $q): array => ['suggested'];

        $reader = $this->reader(['t', 'y', 'p', 'e', 'd', self::ENTER]);

        $result = $this->suggest($closure)->prompt($this->scope, $this->output, $reader);

        self::assertSame('typed', $result);
    }

    #[Test]
    public function escapeDismissesSuggestionsButKeepsValue(): void
    {
        $closure = static fn(string $q): array => $q === '' ? [] : ['suggested'];

        $reader = $this->reader(['x', self::ESCAPE, self::ENTER]);

        $result = $this->suggest($closure)->prompt($this->scope, $this->output, $reader);

        self::assertSame('x', $result);
    }

    #[Test]
    public function asyncClosureRunsThroughScopeCall(): void
    {
        $closure = static fn(string $q): Closure => static fn(): array => ['async-' . $q];

        $reader = $this->reader(['q', self::TAB, self::ENTER]);

        $result = $this->suggest($closure)->prompt($this->scope, $this->output, $reader);

        self::assertSame('async-q', $result);

        $inputCalls = array_filter(
            $this->scope->callReasons,
            static fn($r): bool => $r !== null && $r->kind->value === 'input',
        );
        self::assertNotEmpty($inputCalls);
    }
}
