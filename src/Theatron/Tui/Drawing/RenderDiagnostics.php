<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Drawing;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Task\Task;
use Phalanx\Trace\TraceType;
use Throwable;

final class RenderDiagnostics
{
    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param (Closure(): float)|null $clock
     */
    public function __construct(
        private bool $enabled = false,
        private float $slowThresholdSeconds = 0.05,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * @param (Closure(): float)|null $clock
     */
    public static function enabled(
        float $slowThresholdSeconds = 0.05,
        ?Closure $clock = null,
    ): self {
        return new self(
            enabled: true,
            slowThresholdSeconds: $slowThresholdSeconds,
            clock: $clock,
        );
    }

    /**
     * @template T
     * @param Closure(): T $render
     * @return T
     */
    public function component(Scope $scope, object $component, Closure $render): mixed
    {
        return $this->render($scope, 'component', self::targetName($component), $render);
    }

    /**
     * @template T
     * @param Closure(): T $render
     * @return T
     */
    public function screen(Scope $scope, object $screen, Closure $render): mixed
    {
        return $this->render($scope, 'screen', self::targetName($screen), $render);
    }

    private static function targetName(object $target): string
    {
        $class = $target::class;

        return str_contains($class, '@anonymous') ? 'anonymous' : $class;
    }

    /**
     * @template T
     * @param Closure(): T $render
     * @return T
     */
    private function render(Scope $scope, string $kind, string $target, Closure $render): mixed
    {
        if (!$this->enabled) {
            return $render();
        }

        $startedAt = ($this->clock)();

        try {
            $result = $this->execute($scope, $kind, $target, $render);
        } catch (Cancelled $e) {
            $elapsed = ($this->clock)() - $startedAt;
            $this->record($scope, TraceType::Lifecycle, 'theatron.render.cancelled', $kind, $target, $elapsed);

            throw $e;
        } catch (Throwable $e) {
            $elapsed = ($this->clock)() - $startedAt;
            $this->record($scope, TraceType::Failed, 'theatron.render.failed', $kind, $target, $elapsed, $e);

            throw $e;
        }

        $elapsed = ($this->clock)() - $startedAt;
        if ($elapsed >= $this->slowThresholdSeconds) {
            $this->record($scope, TraceType::Lifecycle, 'theatron.render.slow', $kind, $target, $elapsed);
        }

        return $result;
    }

    /**
     * @template T
     * @param Closure(): T $render
     * @return T
     */
    private function execute(Scope $scope, string $kind, string $target, Closure $render): mixed
    {
        if (!$scope instanceof ExecutionScope) {
            return $render();
        }

        return $scope->execute(
            Task::named(
                "theatron.render.{$kind} {$target}",
                static fn(ExecutionScope $_scope): mixed => $render(),
            ),
        );
    }

    private function record(
        Scope $scope,
        TraceType $type,
        string $name,
        string $kind,
        string $target,
        float $elapsedSeconds,
        ?Throwable $error = null,
    ): void {
        $attrs = [
            'kind' => $kind,
            'target' => $target,
            'elapsed_ms' => max(0.0, $elapsedSeconds * 1000.0),
        ];

        if ($error !== null) {
            $attrs['error'] = $error::class;
            $attrs['message'] = $error->getMessage();
        }

        $scope->trace()->log($type, $name, $attrs);
    }
}
