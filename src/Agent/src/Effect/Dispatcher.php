<?php

declare(strict_types=1);

namespace Phalanx\Agent\Effect;

use Phalanx\Agent\Exception\EffectDenied;
use Phalanx\Agent\Grant\Scope as GrantScope;
use Phalanx\Agent\Grant\Store as GrantStore;
use Phalanx\Agent\Mcp\McpRegistry;
use Phalanx\Agent\Stream\CueEmitter;
use Phalanx\Agent\Tool\ToolExecutor;
use Phalanx\Agent\Tool\ToolRegistry;
use Phalanx\AiProviders\Cue\Effect\Authorized;
use Phalanx\AiProviders\Cue\Effect\Denied;
use Phalanx\AiProviders\Cue\Effect\Executed;
use Phalanx\AiProviders\Cue\Effect\Failed;
use Phalanx\AiProviders\Cue\Effect\Paused;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Effect;
use Phalanx\AiProviders\Effect\Authorizer;
use Phalanx\AiProviders\Effect\Decision;
use Phalanx\AiProviders\Effect\Outcome as AiProvidersOutcome;
use Phalanx\AiProviders\Grant;
use Phalanx\AiProviders\Hazard\Scorer;
use Phalanx\AiProviders\Id;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\TaskScope;

final class Dispatcher
{
    private ToolExecutor $toolExecutor;

    public function __construct(
        private Authorizer $authorizer,
        private Scorer $scorer,
        private GrantStore $grantStore,
        private ToolRegistry $toolRegistry,
        private McpRegistry $mcpRegistry,
        private BuiltInExecutor $builtInExecutor = new BuiltInExecutor(),
        ?ToolExecutor $toolExecutor = null,
    ) {
        $this->toolExecutor = $toolExecutor ?? new ToolExecutor($this->toolRegistry);
    }

    public function dispatch(TaskScope $scope, Requested $request, CueEmitter $emitter): DispatchResult
    {
        $scope->throwIfCancelled();

        $resolution = self::resolve($request, $this->toolRegistry, $this->mcpRegistry);
        $effect = self::buildEffect($request, $this->scorer);
        $grant = $this->grantStore->find($scope, $request->agentId ?? '', $request->kind, $request->arguments);
        $decision = $this->authorizer->evaluate($effect, $grant);
        $seq = $request->sequence;

        if ($decision->isDenied()) {
            return self::handleDenied($request, $emitter, $decision, $resolution, ++$seq);
        }

        if ($decision->isPaused()) {
            return self::handlePaused($request, $emitter, $decision, $resolution, ++$seq);
        }

        return $this->handleGranted($scope, $request, $emitter, $decision, $resolution, $grant, $seq);
    }

    private static function resolve(Requested $request, ToolRegistry $tools, McpRegistry $mcp): Resolution
    {
        if (BuiltInKind::matches($request->effectId)) {
            return Resolution::BuiltIn;
        }

        if ($tools->has($request->effectId)) {
            return Resolution::LocalTool;
        }

        if ($mcp->find($request->effectId) !== null) {
            return Resolution::McpTool;
        }

        throw new \RuntimeException("Unresolvable effect: {$request->effectId}");
    }

    private static function buildEffect(Requested $request, Scorer $scorer): Effect
    {
        $effect = Effect::of(
            id: $request->effectId,
            kind: $request->kind,
            summary: $request->summary,
            arguments: $request->arguments,
            requiresApproval: $request->requiresApproval,
        );

        return $effect->withHazard($scorer->score($effect));
    }

    private static function handleDenied(
        Requested $request,
        CueEmitter $emitter,
        Decision $decision,
        Resolution $resolution,
        int $sequence,
    ): DispatchResult {
        $emitter->emit(new Denied(
            id: 'cue_' . Id::generate(),
            sequence: $sequence,
            activityId: $request->activityId,
            invocationId: $request->invocationId,
            agentId: $request->agentId,
            at: new \DateTimeImmutable(),
            effectId: $request->effectId,
            reasonCodes: $decision->reasonCodes,
        ));

        $error = new EffectDenied(sprintf(
            'Effect "%s" denied: %s',
            $request->effectId,
            implode(', ', $decision->reasonCodes),
        ));

        return DispatchResult::denied(
            Outcome::failed($resolution, $error, AiProvidersOutcome::failed(EffectDenied::class, $error->getMessage(), 0)),
            $error,
        );
    }

    private static function handlePaused(
        Requested $request,
        CueEmitter $emitter,
        Decision $decision,
        Resolution $resolution,
        int $sequence,
    ): DispatchResult {
        $emitter->emit(new Paused(
            id: 'cue_' . Id::generate(),
            sequence: $sequence,
            activityId: $request->activityId,
            invocationId: $request->invocationId,
            agentId: $request->agentId,
            at: new \DateTimeImmutable(),
            effectId: $request->effectId,
            reason: $decision->pauseReason ?? 'Approval required',
        ));

        return DispatchResult::paused(
            Outcome::routed($resolution, AiProvidersOutcome::succeeded(null, 0)),
        );
    }

    private static function executeMcp(TaskScope $scope, Requested $request, McpRegistry $mcp): Outcome
    {
        $mcpOutcome = $mcp->invoke($scope, $request->effectId, $request->arguments);

        if ($mcpOutcome->error !== null) {
            return Outcome::failed(Resolution::McpTool, $mcpOutcome->error, $mcpOutcome->effect);
        }

        if ($mcpOutcome->halt) {
            return Outcome::halted(Resolution::McpTool, $mcpOutcome->effect);
        }

        return Outcome::routed(Resolution::McpTool, $mcpOutcome->effect, $mcpOutcome->data);
    }

    private function handleGranted(
        TaskScope $scope,
        Requested $request,
        CueEmitter $emitter,
        Decision $decision,
        Resolution $resolution,
        ?Grant $grant,
        int $seq,
    ): DispatchResult {
        $emitter->emit(new Authorized(
            id: 'cue_' . Id::generate(),
            sequence: ++$seq,
            activityId: $request->activityId,
            invocationId: $request->invocationId,
            agentId: $request->agentId,
            at: new \DateTimeImmutable(),
            effectId: $request->effectId,
            grantId: $decision->grantId ?? '',
        ));

        $context = new Context(
            activityId: $request->activityId,
            invocationId: $request->invocationId,
            agentId: $request->agentId,
            grant: $grant,
        );

        $start = hrtime(true);

        try {
            $outcome = $this->executeEffect($scope, $request, $context, $resolution);
            $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

            $emitter->emit(new Executed(
                id: 'cue_' . Id::generate(),
                sequence: ++$seq,
                activityId: $request->activityId,
                invocationId: $request->invocationId,
                agentId: $request->agentId,
                at: new \DateTimeImmutable(),
                effectId: $request->effectId,
                durationMs: $elapsed,
            ));

            if ($outcome->halt) {
                return DispatchResult::halted($outcome);
            }

            return DispatchResult::continue($outcome, $outcome->data);
        } catch (Cancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

            $emitter->emit(new Failed(
                id: 'cue_' . Id::generate(),
                sequence: ++$seq,
                activityId: $request->activityId,
                invocationId: $request->invocationId,
                agentId: $request->agentId,
                at: new \DateTimeImmutable(),
                effectId: $request->effectId,
                reason: $e->getMessage(),
                errorClass: $e::class,
            ));

            return DispatchResult::failed(
                Outcome::failed($resolution, $e, AiProvidersOutcome::failed($e::class, $e->getMessage(), $elapsed)),
                $e,
            );
        } finally {
            if ($grant?->scope === GrantScope::Once->value) {
                $this->grantStore->consume($scope, $grant);
            }
        }
    }

    private function executeEffect(
        TaskScope $scope,
        Requested $request,
        Context $context,
        Resolution $resolution,
    ): Outcome {
        return match ($resolution) {
            Resolution::BuiltIn => ($this->builtInExecutor)($scope, $request, $context),
            Resolution::LocalTool => ($this->toolExecutor)($scope, $request, $context),
            Resolution::McpTool => self::executeMcp($scope, $request, $this->mcpRegistry),
            Resolution::SubAgent => throw new \RuntimeException(
                'SubAgent resolution requires an explicit SubAgentExecutor; none is registered.',
            ),
        };
    }
}
