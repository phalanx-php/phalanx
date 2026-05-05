<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Input;

use Phalanx\Archon\Console\Input\KeyReader;
use Phalanx\Scope\Disposable;
use Phalanx\Scope\Suspendable;

/**
 * Test KeyReader that yields a pre-canned sequence of key tokens.
 *
 * Each call to nextKey() pops the next token. When the queue drains, returns
 * the empty string so prompts treat it as cancellation/EOF — tests should
 * always end their key sequence with a terminating key (ENTER, CTRL_C, etc).
 */
final class FakeKeyReader implements KeyReader
{
    public bool $isInteractive {
        get => $this->interactive;
    }

    public int $restoreCalls = 0;
    public int $enableCalls  = 0;

    /** @var list<string> */
    private array $keys;

    /** @param list<string> $keys */
    public function __construct(
        array $keys = [],
        private readonly bool $interactive = true,
    ) {
        $this->keys = $keys;
    }

    public function feed(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->keys[] = $key;
        }
    }

    public function nextKey(Suspendable $scope): string
    {
        return array_shift($this->keys) ?? '';
    }

    public function enableRawMode(Suspendable $scope): void
    {
        $this->enableCalls++;
    }

    public function restore(Suspendable $scope): void
    {
        $this->restoreCalls++;
    }

    public function restoreOnDispose(Disposable&Suspendable $scope): self
    {
        $reader = $this;
        $scope->onDispose(static function () use ($reader, $scope): void {
            $reader->restore($scope);
        });

        return $reader;
    }
}
