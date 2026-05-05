<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Identity;

enum AegisAnnotationSid: string implements RuntimeAnnotationId
{
    case CoroutineId = 'aegis.coroutine_id';
    case EndedAt = 'aegis.ended_at';
    case ParentRunId = 'aegis.parent_run_id';
    case ProcessCommand = 'aegis.process_command';
    case ProcessCwd = 'aegis.process_cwd';
    case ProcessExitCode = 'aegis.process_exit_code';
    case ProcessPid = 'aegis.process_pid';
    case ProcessSignal = 'aegis.process_signal';
    case ProcessState = 'aegis.process_state';
    case ProjectPath = 'aegis.project_path';
    case RunMode = 'aegis.run_mode';
    case RunName = 'aegis.run_name';
    case RunState = 'aegis.run_state';
    case ScopeFqcn = 'aegis.scope_fqcn';
    case ScopeId = 'aegis.scope_id';
    case SourceLine = 'aegis.source_line';
    case SourcePath = 'aegis.source_path';
    case StartedAt = 'aegis.started_at';
    case TaskFqcn = 'aegis.task_fqcn';
    case WaitDetail = 'aegis.wait_detail';
    case WaitKind = 'aegis.wait_kind';
    case WaitSince = 'aegis.wait_since';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
