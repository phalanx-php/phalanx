<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Identity;

enum RuntimeAnnotationSid: string implements RuntimeAnnotationId
{
    case CoroutineId = 'runtime.coroutine_id';
    case EndedAt = 'runtime.ended_at';
    case ParentRunId = 'runtime.parent_run_id';
    case ProcessCommand = 'runtime.process_command';
    case ProcessCwd = 'runtime.process_cwd';
    case ProcessExitCode = 'runtime.process_exit_code';
    case ProcessPid = 'runtime.process_pid';
    case ProcessSignal = 'runtime.process_signal';
    case ProcessState = 'runtime.process_state';
    case ProjectPath = 'runtime.project_path';
    case RunMode = 'runtime.run_mode';
    case RunName = 'runtime.run_name';
    case RunState = 'runtime.run_state';
    case ScopeFqcn = 'runtime.scope_fqcn';
    case ScopeId = 'runtime.scope_id';
    case SourceLine = 'runtime.source_line';
    case SourcePath = 'runtime.source_path';
    case StartedAt = 'runtime.started_at';
    case TaskFqcn = 'runtime.task_fqcn';
    case WaitDetail = 'runtime.wait_detail';
    case WaitKind = 'runtime.wait_kind';
    case WaitSince = 'runtime.wait_since';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
