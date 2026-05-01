<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\PoolLease;
use Phalanx\Supervisor\RunState;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Supervisor\TaskTreeFormatter;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Trace\Trace;
use PHPUnit\Framework\TestCase;

final class TaskTreeFormatterTest extends TestCase
{
    public function testEmptyTreeRendersPlaceholder(): void
    {
        $out = (new TaskTreeFormatter())->format([]);
        self::assertStringContainsString('no live tasks', $out);
    }

    public function testSingleNodeRendersNameStateAndElapsed(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor, 'AppHandler');
        $supervisor->markRunning($run);

        $out = (new TaskTreeFormatter())->format($supervisor->tree($run->id));

        self::assertStringContainsString('AppHandler', $out);
        self::assertStringContainsString('running', $out);
    }

    public function testWaitReasonAppearsInLine(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor, 'FetchUser');
        $supervisor->markRunning($run);
        $clear = $supervisor->beginWait($run, WaitReason::postgres('SELECT * FROM users WHERE id = $1'));
        try {
            $out = (new TaskTreeFormatter())->format($supervisor->tree($run->id));
            self::assertStringContainsString('suspended', $out);
            self::assertStringContainsString('wait: postgres', $out);
            self::assertStringContainsString('SELECT', $out);
        } finally {
            $clear();
        }
    }

    public function testHeldLeasesAppearInLine(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor, 'WritePath');
        $supervisor->markRunning($run);
        $supervisor->registerLease($run, PoolLease::open('postgres/main', 'conn#7'));

        $out = (new TaskTreeFormatter())->format($supervisor->tree($run->id));

        self::assertStringContainsString('holds', $out);
        self::assertStringContainsString('postgres/main', $out);
        self::assertStringContainsString('conn#7', $out);

        $supervisor->reap($run);
    }

    public function testParentChildHierarchyRendersWithBranches(): void
    {
        $supervisor = $this->buildSupervisor();
        $root = $this->openRun($supervisor, 'Root');
        $childA = $supervisor->start(
            new NoopTask(),
            new BareScopeStub(),
            DispatchMode::Concurrent,
            'ChildA',
            $root->id,
        );
        $childB = $supervisor->start(
            new NoopTask(),
            new BareScopeStub(),
            DispatchMode::Concurrent,
            'ChildB',
            $root->id,
        );
        $supervisor->markRunning($root);
        $supervisor->markRunning($childA);
        $supervisor->markRunning($childB);

        $out = (new TaskTreeFormatter())->format($supervisor->tree($root->id));

        self::assertStringContainsString('Root', $out);
        self::assertStringContainsString('ChildA', $out);
        self::assertStringContainsString('ChildB', $out);
        // Children prefixed with one vertical bar per depth.
        $lines = explode("\n", trim($out));
        self::assertStringStartsWith('Root', $lines[0]);
        self::assertStringStartsWith('│ ', $lines[1]);
        self::assertStringStartsWith('│ ', $lines[2]);
    }

    public function testRendersAllRootsWhenNoRootIdGiven(): void
    {
        $supervisor = $this->buildSupervisor();
        $a = $this->openRun($supervisor, 'TaskA');
        $b = $this->openRun($supervisor, 'TaskB');
        $supervisor->markRunning($a);
        $supervisor->markRunning($b);

        $out = (new TaskTreeFormatter())->format($supervisor->tree());

        self::assertStringContainsString('TaskA', $out);
        self::assertStringContainsString('TaskB', $out);
    }

    private function buildSupervisor(): Supervisor
    {
        return new Supervisor(new InProcessLedger(), new Trace());
    }

    private function openRun(Supervisor $supervisor, string $name): TaskRun
    {
        return $supervisor->start(
            new NoopTask(),
            new BareScopeStub(),
            DispatchMode::Inline,
            $name,
        );
    }
}
