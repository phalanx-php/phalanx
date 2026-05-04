<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Input;

use Closure;
use Phalanx\Archon\Console\Input\SearchInput;
use Phalanx\Cancellation\Cancelled;
use PHPUnit\Framework\Attributes\Test;

final class SearchInputTest extends PromptTestCase
{
    private function search(Closure $closure): SearchInput
    {
        return new SearchInput(
            theme: $this->theme,
            label: 'Search',
            search: $closure,
            scroll: 5,
            placeholder: 'Type…',
        );
    }

    #[Test]
    public function synchronousSearchReturnsHighlightedMatch(): void
    {
        $closure = static fn(string $q): array => $q === '' ? [] : ['alpha', 'beta', 'gamma'];

        $reader = $this->reader(['a', self::DOWN, self::ENTER]);

        $result = $this->search($closure)->prompt($this->scope, $this->output, $reader);

        self::assertSame('alpha', $result);
    }

    #[Test]
    public function asyncClosureRunsThroughScopeWithInputWaitReason(): void
    {
        $closure = static fn(string $q): Closure => static fn(): array => ['async-' . $q];

        $reader = $this->reader(['x', self::DOWN, self::ENTER]);

        $result = $this->search($closure)->prompt($this->scope, $this->output, $reader);

        self::assertSame('async-x', $result);
        self::assertNotEmpty($this->scope->callReasons);

        $reasons = array_filter(
            $this->scope->callReasons,
            static fn($r): bool => $r !== null && $r->kind->value === 'input',
        );
        self::assertNotEmpty($reasons);
    }

    #[Test]
    public function noResultsPathStillAllowsQuerySubmit(): void
    {
        $closure = static fn(string $q): array => [];

        $reader = $this->reader(['z', self::ENTER]);

        $result = $this->search($closure)->prompt($this->scope, $this->output, $reader);

        self::assertSame('z', $result);
    }

    #[Test]
    public function nonInteractiveReturnsNull(): void
    {
        $closure = static fn(string $q): array => ['never'];

        $reader = $this->reader([], interactive: false);

        $result = $this->search($closure)->prompt($this->scope, $this->output, $reader);

        self::assertNull($result);
    }

    #[Test]
    public function asyncClosureCancellationPropagatesOutOfPrompt(): void
    {
        $closure = static fn(string $q): Closure
            => static fn(): array => throw new Cancelled('search aborted');

        $reader = $this->reader(['q']);

        $this->expectException(Cancelled::class);
        $this->expectExceptionMessage('search aborted');

        $this->search($closure)->prompt($this->scope, $this->output, $reader);
    }
}
