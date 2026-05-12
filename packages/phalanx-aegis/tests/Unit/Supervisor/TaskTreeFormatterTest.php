<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Scope\Scope;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\PoolLease;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Supervisor\TaskTreeFormatter;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Executable;
use Phalanx\Trace\Trace;
use PHPUnit\Framework\TestCase;

final class TaskTreeFormatterTest extends TestCase
{
    public function testEmptyTreeRendersPlaceholder(): void
    {
        $out = (new TaskTreeFormatter())->format([]);
        self::assertStringContainsString('no active tasks', $out);
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
            new FormatterNoopTask(),
            new FormatterBareScopeStub(),
            DispatchMode::Concurrent,
            'ChildA',
            $root->id,
        );
        $childB = $supervisor->start(
            new FormatterNoopTask(),
            new FormatterBareScopeStub(),
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
        
        $lines = explode("\n", trim($out));
        self::assertStringContainsString('Root', $lines[0]);
        // Sibling logic: only the first child gets the arrow
        self::assertStringContainsString('↳ ChildA', $lines[1]);
        self::assertStringContainsString('  ChildB', $lines[2]);
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
            new FormatterNoopTask(),
            new FormatterBareScopeStub(),
            DispatchMode::Inline,
            $name,
        );
    }
}

/** Dummies for testing */
class FormatterNoopTask implements Executable {
    public function __invoke(\Phalanx\Scope\ExecutionScope $scope): mixed { return null; }
}

class FormatterBareScopeStub implements Scope {
    public \Phalanx\Runtime\RuntimeContext $runtime { get { throw new \Exception(); } }
    public function service(string $id): object { throw new \Exception(); }
    public function attribute(string $key, mixed $default = null): mixed { return $default; }
    public function resource(string $key, mixed $default = null): mixed { return $default; }
    public function withAttribute(string $key, mixed $value): static { return $this; }
    public function trace(): \Phalanx\Trace\Trace { throw new \Exception(); }
}
