<?php

declare(strict_types=1);

namespace Phalanx\Athena\Effect;

use Phalanx\Athena\Exception\EffectDenied;
use Phalanx\Athena\Grant\Store as GrantStore;
use Phalanx\Athena\Mcp\McpRegistry;
use Phalanx\Athena\Stream\CompositeStream;
use Phalanx\Athena\Tool\ToolExecutor;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Panoply\Cue\Effect\Authorized;
use Phalanx\Panoply\Cue\Effect\Denied;
use Phalanx\Panoply\Cue\Effect\Executed;
use Phalanx\Panoply\Cue\Effect\Failed;
use Phalanx\Panoply\Cue\Effect\Paused;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Authorizer;
use Phalanx\Panoply\Effect\Decision;
use Phalanx\Panoply\Effect\Outcome as PanoplyOutcome;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard\Scorer;
use Phalanx\Panoply\Id;
use Phalanx\Scope\TaskScope;

final class Dispatcher
{
    private(set) ToolExecutor $toolExecutor;

    public function __construct(
        private(set) Authorizer $authorizer,
        private(set) Scorer $scorer,
        private(set) GrantStore $grantStore,
        private(set) ToolRegistry $toolRegistry,
        private(set) McpRegistry $mcpRegistry,
        private(set) BuiltInExecutor $builtInExecutor = new BuiltInExecutor(),
        ?ToolExecutor $toolExecutor = null,
    ) {
        $this->toolExecutor = $toolExecutor ?? new ToolExecutor($this->toolRegistry);
    }

    public function dispatch(TaskScope $scope, Requested $request, CompositeStream $stream): DispatchResult
    {
        $scope->throwIfCancelled();

        $resolution = self::resolve($request, $this->toolRegistry, $this->mcpRegistry);
        $effect = self::buildEffect($request, $this->scorer);
        $grant = $this->grantStore->find($scope, $request->agentId ?? '', $request->kind, $request->arguments);
        $decision = $this->authorizer->evaluate($effect, $grant);
        $seq = $request->sequence;

        if ($decision->isDenied()) {
            return self::handleDenied($request, $stream, $decision, $resolution, ++$seq);
        }

        if ($decision->isPaused()) {
            return self::handlePaused($request, $stream, $decision, $resolution, ++$seq);
        }

        return $this->handleGranted($scope, $request, $stream, $decision, $resolution, $grant, $seq);
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
        CompositeStream $stream,
        Decision $decision,
        Resolution $resolution,
        int $sequence,
    ): DispatchResult {
        $stream->emit(new Denied(
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
            Outcome::failed($resolution, $error, PanoplyOutcome::failed(EffectDenied::class, $error->getMessage(), 0)),
            $error,
        );
    }

    private static function handlePaused(
        Requested $request,
        CompositeStream $stream,
        Decision $decision,
        Resolution $resolution,
        int $sequence,
    ): DispatchResult {
        $stream->emit(new Paused(
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
            Outcome::routed($resolution, PanoplyOutcome::succeeded(null, 0)),
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
        CompositeStream $stream,
        Decision $decision,
        Resolution $resolution,
        ?Grant $grant,
        int $seq,
    ): DispatchResult {
        $stream->emit(new Authorized(
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

            $stream->emit(new Executed(
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

            $stream->emit(new Failed(
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
                Outcome::failed($resolution, $e, PanoplyOutcome::failed($e::class, $e->getMessage(), $elapsed)),
                $e,
            );
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
