<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

enum ConversationTurnEventKind: string
{
    case ActivityCancelled = 'activity.cancelled';
    case ActivityCompleted = 'activity.completed';
    case ActivityFailed = 'activity.failed';
    case ActivityStarted = 'activity.started';
    case EffectArgumentsDelta = 'effect.arguments_delta';
    case EffectAuthorized = 'effect.authorized';
    case EffectDenied = 'effect.denied';
    case EffectExecuted = 'effect.executed';
    case EffectFailed = 'effect.failed';
    case EffectPaused = 'effect.paused';
    case EffectRequested = 'effect.requested';
    case InvocationCancelled = 'invocation.cancelled';
    case InvocationCompleted = 'invocation.completed';
    case InvocationFailed = 'invocation.failed';
    case InvocationStarted = 'invocation.started';
    case MessageDelta = 'output.message_delta';
    case ReasoningDelta = 'output.reasoning_delta';
    case RuntimeError = 'runtime.error';
    case RuntimeNotice = 'runtime.notice';
    case RuntimeWarning = 'runtime.warning';
    case ThinkingDelta = 'output.thinking_delta';
    case TokenStop = 'output.token_stop';
    case UsageDelta = 'usage.delta';
    case UsageFinal = 'usage.final';
}
