<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

enum ConversationTurnEventKind: string
{
    case ActivityCancelled = 'activity.cancelled';
    case ActivityCompleted = 'activity.completed';
    case ActivityFailed = 'activity.failed';
    case ActivityStarted = 'activity.started';
    case ArtifactDelta = 'artifact.delta';
    case ArtifactDrafting = 'artifact.drafting';
    case ArtifactFinalized = 'artifact.finalized';
    case EffectArgumentsDelta = 'effect.arguments_delta';
    case EffectAuthorized = 'effect.authorized';
    case EffectDenied = 'effect.denied';
    case EffectExecuted = 'effect.executed';
    case EffectFailed = 'effect.failed';
    case EffectLogged = 'effect.logged';
    case EffectPaused = 'effect.paused';
    case EffectRequested = 'effect.requested';
    case GrantAvailable = 'grant.available';
    case InvocationCancelled = 'invocation.cancelled';
    case InvocationCompleted = 'invocation.completed';
    case InvocationFailed = 'invocation.failed';
    case InvocationStarted = 'invocation.started';
    case MessageDelta = 'output.message_delta';
    case ProviderRateLimited = 'provider.rate_limited';
    case ProviderResolved = 'provider.resolved';
    case ProviderRetrying = 'provider.retrying';
    case ReasoningDelta = 'output.reasoning_delta';
    case RuntimeClientConnected = 'runtime.client_connected';
    case RuntimeClientDisconnected = 'runtime.client_disconnected';
    case RuntimeError = 'runtime.error';
    case RuntimeNotice = 'runtime.notice';
    case RuntimeWarning = 'runtime.warning';
    case StructuredDelta = 'output.structured_delta';
    case ThinkingDelta = 'output.thinking_delta';
    case TokenStop = 'output.token_stop';
    case UsageDelta = 'usage.delta';
    case UsageFinal = 'usage.final';
}
