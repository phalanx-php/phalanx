<?php

declare(strict_types=1);

namespace Phalanx\Dory;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Dory\Orchestration\AttemptBuilder;
use Phalanx\Dory\Rendering\EchoSink;
use Phalanx\Dory\Rendering\OutputSink;
use Phalanx\Dory\Rendering\ValueRendererPipeline;
use Phalanx\Grammata\Files;
use Phalanx\Iris\HttpClient;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;
use Throwable;

final class DoryExecutionContext implements ScriptContext
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

    private ?Files $fsBacking = null;
    private ?HttpClient $httpBacking = null;
    private ?OutputSink $sinkBacking = null;
    private ?ValueRendererPipeline $pipelineBacking = null;

    private bool $pipelineResolved = false;

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
        $sink = $this->resolveSink();
        $pipeline = $this->resolvePipeline();

        foreach ($values as $value) {
            if ($pipeline !== null) {
                $pipeline->render($value, $sink);
            } else {
                $sink->line(is_string($value) ? $value : var_export($value, true));
            }
        }
    }

    public function println(string $message = ''): void
    {
        $this->resolveSink()->line($message);
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }

    private function resolveSink(): OutputSink
    {
        if ($this->sinkBacking !== null) {
            return $this->sinkBacking;
        }

        try {
            $resolved = $this->service(OutputSink::class);
            $sink = $resolved instanceof OutputSink ? $resolved : new EchoSink();
        } catch (Cancelled $e) {
            throw $e;
        } catch (Throwable) {
            $sink = new EchoSink();
        }

        return $this->sinkBacking = $sink;
    }

    private function resolvePipeline(): ?ValueRendererPipeline
    {
        if ($this->pipelineResolved) {
            return $this->pipelineBacking;
        }

        try {
            $resolved = $this->service(ValueRendererPipeline::class);
            $this->pipelineBacking = $resolved instanceof ValueRendererPipeline ? $resolved : null;
        } catch (Cancelled $e) {
            throw $e;
        } catch (Throwable) {
            $this->pipelineBacking = null;
        }

        $this->pipelineResolved = true;

        return $this->pipelineBacking;
    }
}
