<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tui\Drawing;

use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\TaskRunSnapshot;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Theatron\Tui\Drawing\RenderDiagnostics;
use Phalanx\Theatron\Tests\Support\ClockProbe;
use Phalanx\Theatron\Tests\Support\RecordingTaskScope;
use PHPUnit\Framework\Attributes\Test;

final class RenderDiagnosticsTest extends PhalanxTestCase
{
    #[Test]
    public function disabledDiagnosticsRunRenderWithoutTraceOrTaskBoundary(): void
    {
        $scope = new RecordingTaskScope();
        $diagnostics = new RenderDiagnostics();

        $result = $diagnostics->component(
            $scope,
            new RenderDiagnosticsProbeTarget(),
            static fn(): string => 'ok',
        );

        self::assertSame('ok', $result);
        self::assertSame(0, $scope->callCount());
        self::assertNull($scope->lastWaitReason());
        self::assertSame([], $scope->trace()->events());
    }

    #[Test]
    public function enabledFastRenderDoesNotLogSlowTrace(): void
    {
        $clock = new ClockProbe(1.0, 1.001);
        $scope = new RecordingTaskScope();
        $diagnostics = RenderDiagnostics::enabled(
            slowThresholdSeconds: 0.05,
            clock: $clock(...),
        );

        $result = $diagnostics->component(
            $scope,
            new RenderDiagnosticsProbeTarget(),
            static fn(): string => 'ok',
        );

        self::assertSame('ok', $result);
        self::assertSame(0, $scope->callCount());
        self::assertNull($scope->lastWaitReason());
        self::assertSame([], $scope->trace()->events());
        self::assertTrue($clock->isExhausted());
    }

    #[Test]
    public function enabledDiagnosticsRunExecutionScopeRenderInNamedTaskWithoutWaitReason(): void
    {
        $clock = new ClockProbe(1.0, 1.001);
        $diagnostics = RenderDiagnostics::enabled(
            slowThresholdSeconds: 0.05,
            clock: $clock(...),
        );
        $target = new RenderDiagnosticsProbeTarget();

        $this->scope->run(static function (ExecutionScope $scope) use ($diagnostics, $target): void {
            $outerRun = $scope->currentRunSnapshot();
            $renderRun = null;

            $result = $diagnostics->component(
                $scope,
                $target,
                static function () use ($scope, &$renderRun): string {
                    $renderRun = $scope->currentRunSnapshot();

                    return 'ok';
                },
            );

            self::assertSame('ok', $result);
            self::assertInstanceOf(TaskRunSnapshot::class, $outerRun);
            self::assertInstanceOf(TaskRunSnapshot::class, $renderRun);
            self::assertSame('theatron.render.component ' . RenderDiagnosticsProbeTarget::class, $renderRun->name);
            self::assertSame($outerRun->id, $renderRun->parentId);
            self::assertNull($renderRun->currentWait);
            self::assertSame($outerRun->id, $scope->currentRunSnapshot()?->id);
        });

        self::assertTrue($clock->isExhausted());
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::TaskRun));
    }
}

final class RenderDiagnosticsProbeTarget
{
}
