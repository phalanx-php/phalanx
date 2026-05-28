<?php

declare(strict_types=1);

namespace Phx;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\Iris;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;

/**
 * The Dory ScriptScope injected as $dory into one-off scripts.
 * It delegates core scope behavior (cancellation, execution, delay, etc.)
 * to the underlying CommandScope, but exposes friendly properties for
 * high-level services like HTTP, Filesystem, and Logging.
 *
 * @property-read HttpClient $http
 */
final class ScriptScope implements ExecutionScope
{
    use ExecutionScopeDelegate;

    public function __construct(
        private CommandScope $inner,
    ) {
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'http' => Iris::client($this->inner),
            'id' => $this->inner->id,
            default => throw new \InvalidArgumentException("Undefined property '$name' on ScriptScope."),
        };
    }
}
