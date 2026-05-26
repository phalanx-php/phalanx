<?php

declare(strict_types=1);

namespace Phalanx\Dory;

use Closure;
use Phalanx\Dory\Orchestration\AttemptBuilder;
use Phalanx\Grammata\Files;
use Phalanx\Iris\HttpClient;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;

final class ScriptContext implements ScriptScope
{
    use ExecutionScopeDelegate;

    public string $scriptName {
        get => basename($this->scriptPath);
    }

    public HttpClient $http {
        get => $this->httpBacking ??= $this->service(HttpClient::class);
    }

    public Files $fs {
        get => $this->fsBacking ??= $this->service(Files::class);
    }

    private ?HttpClient $httpBacking = null;
    private ?Files $fsBacking = null;

    public function __construct(
        private ExecutionScope $inner,
        private(set) string $scriptPath,
        private(set) DoryConfig $config,
    ) {
    }

    public function attempt(Closure $task): AttemptBuilder
    {
        return new AttemptBuilder($this, $task);
    }

    public function dump(mixed ...$values): void
    {
        foreach ($values as $value) {
            $this->println(is_string($value) ? $value : var_export($value, true));
        }
    }

    public function println(string $message = ''): void
    {
        echo $message . "\n";
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }
}
