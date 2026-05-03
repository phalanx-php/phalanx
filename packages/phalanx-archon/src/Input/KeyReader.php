<?php

declare(strict_types=1);

namespace Phalanx\Archon\Input;

use Phalanx\Scope\Disposable;
use Phalanx\Scope\Suspendable;

/**
 * Archon-side abstraction over keypress reading. Production implementation
 * is RawInput (drains bytes from Aegis ConsoleInput through KeyParser).
 * Tests use FakeKeyReader to feed pre-parsed key tokens directly.
 *
 * nextKey() suspends through the supervised scope until the next complete
 * key token is available. Returns the empty string on EOF or scope
 * cancellation; callers treat that as "input ended".
 */
interface KeyReader
{
    public bool $isInteractive { get; }

    public function nextKey(Suspendable $scope): string;

    public function enableRawMode(Suspendable $scope): void;

    public function restore(Suspendable $scope): void;

    public function restoreOnDispose(Disposable&Suspendable $scope): self;
}
