<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Swoole\Coroutine as Co;
use Phalanx\Athena\Activity;
use Phalanx\Athena\Hook\StepContext;
use Phalanx\Athena\Hook\StepHookChain;
use Phalanx\Athena\Tests\Fixtures\TestAgent;
use Phalanx\Athena\Turn\AegisRuntimeFactory;
use Phalanx\Athena\Turn\Builder;
use Phalanx\Athena\Turn\Config as TurnConfig;
use Phalanx\Athena\Turn\DefaultBuilder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Conversation\Record\ToolCall;
use Phalanx\Panoply\Conversation\Record\ToolResult;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Effect\Authorized;
use Phalanx\Panoply\Cue\Effect\Executed;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Id;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider as ProviderContract;
use Phalanx\Panoply\Provider\Fake\Provider as FakeProvider;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Stream;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Styx\Channel as StyxChannel;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;

#[RequiresPhpExtension('openswoole')]
final class ChannelBackedLoopTest extends PhalanxTestCase
{
    // -- Scenario 1 --------------------------------------------------------

    #[Test]
    public function single_invocation_streams_cues_through_channel(): void
    {
        $script = self::tokenScript(50, 'act_1', 'Hello world');

        $this->scope->run(static function (ExecutionScope $scope) use ($script): void {
            $loop = new ProofChannelLoop(new DefaultBuilder(), new FakeProvider($script, Capabilities::empty()));
            $result = $loop($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), 1));

            $cues = $result->stream->toArray();

            self::assertCount(51, $cues);
            self::assertInstanceOf(TokenDelta::class, $cues[0]);
            self::assertInstanceOf(TokenStop::class, $cues[50]);
            self::assertSame(Outcome::Complete, $result->outcome);
            self::assertSame(Activity\State::Completed, $result->state);
            self::assertSame(1, $result->invocations);

            $messages = array_filter($result->log->toArray(), static fn($r) => $r instanceof Message);
            self::assertCount(1, $messages);
        });

        $this->scope->expect->runtime()->clean();
    }

    // -- Scenario 2 --------------------------------------------------------

    #[Test]
    public function multi_invocation_with_tool_calls_maintains_order(): void
    {
        $at = new \DateTimeImmutable();
        $scripts = [
            self::toolCallScript(20, 'act_1', 'eff_tool_1', $at),
            self::toolCallScript(30, 'act_1', 'eff_tool_2', $at),
            self::tokenScript(10, 'act_1', 'Final answer'),
        ];

        $this->scope->run(static function (ExecutionScope $scope) use ($scripts): void {
            $loop = new ProofChannelLoop(
                new DefaultBuilder(),
                new ScriptedProvider($scripts, Capabilities::empty()),
                effectHandler: new InlineEffectHandler(),
            );

            $result = $loop($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), maxInvocations: 3));

            $cues = $result->stream->toArray();
            $types = array_map(static fn(Cue $c): string => $c->type, $cues);

            self::assertSame(3, $result->invocations);
            self::assertSame(Outcome::Complete, $result->outcome);

            self::assertSame(20, self::countType($types, 'cue.output.token_delta', 0, 20));
            self::assertSame('cue.effect.requested', $types[20]);
            self::assertSame('cue.effect.authorized', $types[21]);
            self::assertSame('cue.effect.executed', $types[22]);

            self::assertSame(30, self::countType($types, 'cue.output.token_delta', 23, 30));
            self::assertSame('cue.effect.requested', $types[53]);
            self::assertSame('cue.effect.authorized', $types[54]);
            self::assertSame('cue.effect.executed', $types[55]);

            self::assertSame(10, self::countType($types, 'cue.output.token_delta', 56, 10));
            self::assertSame('cue.output.token_stop', $types[66]);

            $records = $result->log->toArray();
            $toolCalls = array_filter($records, static fn($r) => $r instanceof ToolCall);
            $toolResults = array_filter($records, static fn($r) => $r instanceof ToolResult);
            self::assertCount(2, $toolCalls);
            self::assertCount(2, $toolResults);
        });

        $this->scope->expect->runtime()->clean();
    }

    // -- Scenario 3 --------------------------------------------------------

    #[Test]
    public function backpressure_bounds_memory_under_slow_consumer(): void
    {
        $script = self::tokenScript(200, 'act_bp', 'pressure test');

        $this->scope->run(static function (ExecutionScope $scope) use ($script): void {
            $loop = new ProofChannelLoop(
                new DefaultBuilder(),
                new FakeProvider($script, Capabilities::empty()),
                channelBuffer: 4,
            );

            $result = $loop($scope, new TestAgent(), new Activity\Config('act_bp', Context::new(), 1));

            $peakMemory = memory_get_usage();
            $baseMemory = $peakMemory;
            $count = 0;

            foreach ($result->stream as $cue) {
                $count++;
                Co::usleep(1000);
                $current = memory_get_usage();
                if ($current > $peakMemory) {
                    $peakMemory = $current;
                }
            }

            self::assertSame(201, $count);
            self::assertSame(Outcome::Complete, $result->outcome);

            $memoryGrowth = $peakMemory - $baseMemory;
            self::assertLessThan(
                2 * 1024 * 1024,
                $memoryGrowth,
                sprintf('Memory grew by %s bytes during slow consumption — channel should bound this', number_format($memoryGrowth)),
            );
        });

        $this->scope->expect->runtime()->clean();
    }

    // -- Scenario 4 --------------------------------------------------------

    #[Test]
    public function cancellation_shuts_down_producer_and_consumer(): void
    {
        $script = self::tokenScript(1000, 'act_cancel', 'stream forever');

        $cuesReceived = 0;
        $caughtCancelled = false;

        $this->scope->run(static function (ExecutionScope $scope) use ($script, &$cuesReceived, &$caughtCancelled): void {
            $token = CancellationToken::create();

            $childResult = $scope->execute(\Phalanx\Task\Task::named(
                'cancel-test',
                static function (ExecutionScope $childScope) use ($script, $token, &$cuesReceived, &$caughtCancelled): void {
                    $loop = new ProofChannelLoop(
                        new DefaultBuilder(),
                        new FakeProvider($script, Capabilities::empty()),
                        cancellation: $token,
                    );

                    $result = $loop($childScope, new TestAgent(), new Activity\Config('act_cancel', Context::new(), 1));

                    try {
                        foreach ($result->stream as $cue) {
                            $cuesReceived++;
                            if ($cuesReceived >= 10) {
                                $token->cancel();
                            }
                        }
                    } catch (Cancelled) {
                        $caughtCancelled = true;
                    }
                },
            ));
        });

        self::assertGreaterThanOrEqual(10, $cuesReceived);
        self::assertLessThan(1000, $cuesReceived);
        self::assertTrue($caughtCancelled, 'Consumer should have caught Cancelled exception');
        $this->scope->expect->runtime()->clean();
    }

    // -- Scenario 5 --------------------------------------------------------

    #[Test]
    public function producer_error_propagates_through_channel(): void
    {
        $at = new \DateTimeImmutable();
        $script = self::deltaList(20, 'act_err', $at);
        $error = new \RuntimeException('provider exploded');

        $cuesReceived = 0;
        $caughtError = null;

        $this->scope->run(static function (ExecutionScope $scope) use ($script, $error, &$cuesReceived, &$caughtError): void {
            $loop = new ProofChannelLoop(
                new DefaultBuilder(),
                new ExplodingProvider($script, $error, Capabilities::empty()),
            );

            $result = $loop($scope, new TestAgent(), new Activity\Config('act_err', Context::new(), 1));

            try {
                foreach ($result->stream as $cue) {
                    $cuesReceived++;
                }
            } catch (\RuntimeException $e) {
                $caughtError = $e;
            }
        });

        self::assertSame(20, $cuesReceived);
        self::assertNotNull($caughtError);
        self::assertSame('provider exploded', $caughtError->getMessage());
        $this->scope->expect->runtime()->clean();
    }

    // -- Scenario 6 --------------------------------------------------------

    #[Test]
    public function memory_usage_dramatically_lower_than_materialized_array(): void
    {
        $tokenCount = 5000;
        $script = self::tokenScript($tokenCount, 'act_mem', 'memory test');

        $channelPeak = $this->scope->run(static function (ExecutionScope $scope) use ($script, $tokenCount): int {
            $loop = new ProofChannelLoop(
                new DefaultBuilder(),
                new FakeProvider($script, Capabilities::empty()),
            );

            gc_collect_cycles();
            $baseline = memory_get_usage();
            $peak = $baseline;

            $result = $loop($scope, new TestAgent(), new Activity\Config('act_mem', Context::new(), 1));

            $count = 0;
            foreach ($result->stream as $cue) {
                $count++;
                if ($count % 100 === 0) {
                    $current = memory_get_usage();
                    if ($current > $peak) {
                        $peak = $current;
                    }
                }
            }

            self::assertSame($tokenCount + 1, $count);
            self::assertSame(Outcome::Complete, $result->outcome);

            return $peak - $baseline;
        });

        self::assertLessThan(
            2 * 1024 * 1024,
            $channelPeak,
            sprintf(
                'Channel peak (%s bytes) should stay under 2MB for 5000 cues — bounded by channel buffer',
                number_format($channelPeak),
            ),
        );

        $this->scope->expect->runtime()->clean();
    }

    // -- Scale: Volume -----------------------------------------------------

    #[Test]
    #[Group('scale')]
    public function scale_high_volume_single_loop(): void
    {
        $cueCount = 100_000;
        $script = self::tokenScript($cueCount, 'act_vol', 'v');

        $this->scope->run(static function (ExecutionScope $scope) use ($script, $cueCount): void {
            $loop = new ProofChannelLoop(
                new DefaultBuilder(),
                new FakeProvider($script, Capabilities::empty()),
                channelBuffer: 64,
            );

            gc_collect_cycles();
            $baseline = memory_get_usage();
            $peak = $baseline;

            $result = $loop($scope, new TestAgent(), new Activity\Config('act_vol', Context::new(), 1));

            $count = 0;
            foreach ($result->stream as $cue) {
                $count++;
                if ($count % 5000 === 0) {
                    $current = memory_get_usage();
                    if ($current > $peak) {
                        $peak = $current;
                    }
                }
            }

            self::assertSame($cueCount + 1, $count);
            self::assertSame(Outcome::Complete, $result->outcome);

            $growth = $peak - $baseline;
            self::assertLessThan(
                4 * 1024 * 1024,
                $growth,
                sprintf('Memory grew by %s bytes over 100K cues — channel buffer should bound this', number_format($growth)),
            );
        });

        $this->scope->expect->runtime()->clean();
    }

    // -- Scale: Concurrency ------------------------------------------------

    #[Test]
    #[Group('scale')]
    public function scale_concurrent_activities(): void
    {
        $activityCount = 10;
        $cuesPerActivity = 5_000;
        $expected = $cuesPerActivity + 1;

        $this->scope->run(static function (ExecutionScope $scope) use ($activityCount, $cuesPerActivity, $expected): void {
            $collector = new StyxChannel($activityCount);

            for ($a = 0; $a < $activityCount; $a++) {
                $actId = 'act_conc_' . $a;
                $script = self::tokenScript($cuesPerActivity, $actId, 'c' . $a . '_');
                $idx = $a;

                $loop = new ProofChannelLoop(
                    new DefaultBuilder(),
                    new FakeProvider($script, Capabilities::empty()),
                    channelBuffer: 16,
                );

                $result = $loop($scope, new TestAgent(), new Activity\Config($actId, Context::new(), 1));

                $scope->go(static function () use ($result, $idx, $collector): void {
                    $count = 0;
                    foreach ($result->stream as $cue) {
                        $count++;
                    }
                    $collector->emit([$idx, $count, $result->outcome]);
                }, 'consumer.' . $idx);
            }

            for ($a = 0; $a < $activityCount; $a++) {
                [$idx, $count, $outcome] = $collector->next();
                self::assertSame($expected, $count, "Activity {$idx} should receive all {$expected} cues");
                self::assertSame(Outcome::Complete, $outcome, "Activity {$idx} should complete");
            }
        });

        $this->scope->expect->runtime()->clean();
    }

    // -- Scale: Sustained cycling ------------------------------------------

    #[Test]
    #[Group('scale')]
    public function scale_sustained_cycling_no_memory_accumulation(): void
    {
        $cycles = 50;
        $cuesPerCycle = 1_000;

        $memorySnapshots = [];

        $this->scope->run(static function (ExecutionScope $scope) use ($cycles, $cuesPerCycle, &$memorySnapshots): void {
            for ($cycle = 0; $cycle < $cycles; $cycle++) {
                $actId = 'act_cycle_' . $cycle;
                $script = self::tokenScript($cuesPerCycle, $actId, 'cy');

                $loop = new ProofChannelLoop(
                    new DefaultBuilder(),
                    new FakeProvider($script, Capabilities::empty()),
                    channelBuffer: 16,
                );

                $result = $loop($scope, new TestAgent(), new Activity\Config($actId, Context::new(), 1));

                $count = 0;
                foreach ($result->stream as $cue) {
                    $count++;
                }

                self::assertSame($cuesPerCycle + 1, $count);
                self::assertSame(Outcome::Complete, $result->outcome);

                unset($result, $loop, $script);

                if ($cycle % 5 === 0) {
                    gc_collect_cycles();
                    $memorySnapshots[] = memory_get_usage();
                }
            }
        });

        self::assertGreaterThanOrEqual(2, count($memorySnapshots));

        $first = $memorySnapshots[0];
        $last = $memorySnapshots[count($memorySnapshots) - 1];
        $drift = $last - $first;

        self::assertLessThan(
            4 * 1024 * 1024,
            $drift,
            sprintf(
                'Memory drifted %s bytes over %d cycles (first: %s, last: %s) — suggests accumulation',
                number_format($drift),
                $cycles,
                number_format($first),
                number_format($last),
            ),
        );

        $this->scope->expect->runtime()->clean();
    }

    // -- Helpers -----------------------------------------------------------

    /**
     * @return list<Cue>
     */
    private static function tokenScript(int $deltaCount, string $activityId, string $word): array
    {
        $at = new \DateTimeImmutable();
        $cues = self::deltaList($deltaCount, $activityId, $at, $word);
        $cues[] = new TokenStop(
            'cue_stop',
            $deltaCount + 1,
            $activityId,
            null,
            'athena-test-agent',
            $at,
            StopReason::EndOfTurn,
        );

        return $cues;
    }

    /**
     * @return list<Cue>
     */
    private static function toolCallScript(int $deltaCount, string $activityId, string $effectId, \DateTimeImmutable $at): array
    {
        $cues = self::deltaList($deltaCount, $activityId, $at);
        $cues[] = new Requested(
            id: 'cue_req_' . $effectId,
            sequence: $deltaCount + 1,
            activityId: $activityId,
            invocationId: null,
            agentId: 'athena-test-agent',
            at: $at,
            effectId: $effectId,
            kind: Kind::FileRead,
            summary: 'read file',
        );

        return $cues;
    }

    /**
     * @return list<Cue>
     */
    private static function deltaList(int $count, string $activityId, \DateTimeImmutable $at, string $word = 'tok'): array
    {
        $cues = [];
        for ($i = 1; $i <= $count; $i++) {
            $cues[] = new TokenDelta(
                'cue_d_' . $i,
                $i,
                $activityId,
                null,
                'athena-test-agent',
                $at,
                $word . $i . ' ',
            );
        }

        return $cues;
    }

    /**
     * @param list<string> $types
     */
    private static function countType(array $types, string $target, int $offset, int $length): int
    {
        return count(array_filter(array_slice($types, $offset, $length), static fn(string $t) => $t === $target));
    }
}

// ---------------------------------------------------------------------------
// Ad-hoc types for the proof. These live in the test file and use existing
// Phalanx primitives; nothing here modifies framework source.
// ---------------------------------------------------------------------------

final class ScriptedProvider implements ProviderContract
{
    private int $callIndex = 0;

    /** @param list<list<Cue>> $scripts */
    public function __construct(
        private array $scripts,
        private Capabilities $capabilities,
    ) {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        $script = $this->scripts[$this->callIndex++] ?? [];

        return new Stream(static function () use ($script, $runtime): \Generator {
            foreach ($script as $cue) {
                $runtime->throwIfCancelled();
                yield $cue;
            }
        });
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }
}

final class ExplodingProvider implements ProviderContract
{
    /**
     * @param list<Cue> $script
     */
    public function __construct(
        private array $script,
        private \Throwable $error,
        private Capabilities $capabilities,
    ) {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        $script = $this->script;
        $error = $this->error;

        return new Stream(static function () use ($script, $runtime, $error): \Generator {
            foreach ($script as $cue) {
                $runtime->throwIfCancelled();
                yield $cue;
            }
            throw $error;
        });
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }
}

final class InlineEffectHandler
{
    /** @param array<string, mixed> $cannedData */
    public function __construct(
        private array $cannedData = ['result' => 'ok'],
    ) {
    }

    /**
     * @return array{0: Outcome, 1: ?\Throwable, 2: mixed}
     */
    public function handle(TaskScope $scope, Requested $request, StyxChannel $channel, string $activityId): array
    {
        $at = new \DateTimeImmutable();
        $seq = $request->sequence;

        $channel->emit(new Authorized(
            id: 'cue_auth_' . $request->effectId,
            sequence: ++$seq,
            activityId: $activityId,
            invocationId: $request->invocationId,
            agentId: $request->agentId,
            at: $at,
            effectId: $request->effectId,
            grantId: 'grant_test',
        ));

        $channel->emit(new Executed(
            id: 'cue_exec_' . $request->effectId,
            sequence: ++$seq,
            activityId: $activityId,
            invocationId: $request->invocationId,
            agentId: $request->agentId,
            at: $at,
            effectId: $request->effectId,
            durationMs: 1,
        ));

        return [Outcome::Continue, null, $this->cannedData];
    }
}

final class ProofTerminalState
{
    public function __construct(
        private(set) Outcome $outcome,
        private(set) Log $log,
        private(set) int $invocations,
        private(set) ?\Throwable $error = null,
    ) {
    }
}

final class ProofLazyResult
{
    private ?ProofTerminalState $terminal = null;

    public Activity\State $state {
        get => self::stateFor($this->resolveTerminal()->outcome);
    }

    public Outcome $outcome {
        get => $this->resolveTerminal()->outcome;
    }

    public Log $log {
        get => $this->resolveTerminal()->log;
    }

    public int $invocations {
        get => $this->resolveTerminal()->invocations;
    }

    public ?\Throwable $error {
        get => $this->resolveTerminal()->error;
    }

    /** @param \ArrayObject<int, ProofTerminalState> $terminalHolder */
    public function __construct(
        private(set) string $activityId,
        private(set) Stream $stream,
        private \ArrayObject $terminalHolder,
    ) {
    }

    private function resolveTerminal(): ProofTerminalState
    {
        if ($this->terminal !== null) {
            return $this->terminal;
        }
        $value = $this->terminalHolder->offsetExists(0) ? $this->terminalHolder[0] : null;
        if (!$value instanceof ProofTerminalState) {
            throw new \RuntimeException('Terminal state not yet available — consume the stream first');
        }
        $this->terminal = $value;

        return $this->terminal;
    }

    private static function stateFor(Outcome $outcome): Activity\State
    {
        return match ($outcome) {
            Outcome::Complete => Activity\State::Completed,
            Outcome::WaitingForApproval => Activity\State::Suspended,
            Outcome::Cancelled => Activity\State::Cancelled,
            default => Activity\State::Failed,
        };
    }
}

final class ProofChannelLoop
{
    public function __construct(
        private Builder $builder,
        private ProviderContract $provider,
        private int $channelBuffer = 32,
        /** @var list<\Phalanx\Athena\Hook\StepHook> */
        private array $hooks = [],
        private ?InlineEffectHandler $effectHandler = null,
        private ?CancellationToken $cancellation = null,
    ) {
    }

    public function __invoke(TaskScope $scope, Agent $agent, Activity\Config $config, ?Log $log = null): ProofLazyResult
    {
        $cueChannel = new StyxChannel($this->channelBuffer);
        /** @var \ArrayObject<int, ProofTerminalState> $terminalHolder */
        $terminalHolder = new \ArrayObject();

        $records = $log !== null ? $log->toArray() : [];
        $turn = new TurnConfig($config->id, $config->context, $config->maxInvocations);
        $runtime = (new AegisRuntimeFactory())($scope);
        $chain = new StepHookChain([...$this->hooks, ...$config->hooks]);

        $builder = $this->builder;
        $provider = $this->provider;
        $effectHandler = $this->effectHandler;
        $cancellation = $this->cancellation;

        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('ProofChannelLoop requires ExecutionScope for go()');
        }

        $scope->go(static function (ExecutionScope $producerScope) use (
            $cueChannel,
            $terminalHolder,
            $records,
            $turn,
            $runtime,
            $chain,
            $builder,
            $provider,
            $effectHandler,
            $agent,
            $config,
            $cancellation,
        ): void {
            $outcome = Outcome::Continue;
            $error = null;
            $invocationCount = 0;

            try {
                for ($i = 1; $i <= $config->maxInvocations; $i++) {
                    $producerScope->throwIfCancelled();
                    $runtime->throwIfCancelled();

                    if ($cancellation !== null) {
                        $cancellation->throwIfCancelled();
                    }

                    $current = Log::from($records);
                    $turnConfig = $turn->forInvocation($i);
                    $invocation = $builder->build($producerScope, $agent, $current, $turnConfig);

                    $hookResult = $chain->notify($producerScope, StepContext::beforeInvocation($turnConfig, $current, $invocation));
                    if ($hookResult->outcome->terminal()) {
                        $outcome = $hookResult->outcome;
                        $error = $hookResult->error;
                        break;
                    }

                    $text = '';
                    $providerStream = $provider->perform($invocation, $runtime);

                    foreach ($providerStream as $cue) {
                        if ($cancellation !== null) {
                            $cancellation->throwIfCancelled();
                        }

                        $cueChannel->emit($cue);

                        $hookResult = $chain->notify($producerScope, StepContext::afterCue($turnConfig, $current, $invocation, $cue));
                        if ($hookResult->outcome->terminal()) {
                            $outcome = $hookResult->outcome;
                            $error = $hookResult->error;
                            break 2;
                        }

                        if ($cue instanceof TokenDelta) {
                            $text .= $cue->text;
                            continue;
                        }

                        if ($cue instanceof TokenStop) {
                            $outcome = Outcome::Complete;
                            continue;
                        }

                        if ($cue instanceof Requested) {
                            if ($effectHandler !== null) {
                                [$effectOutcome, $effectError, $data] = $effectHandler->handle(
                                    $producerScope,
                                    $cue,
                                    $cueChannel,
                                    $config->id,
                                );

                                if ($effectOutcome->terminal()) {
                                    $outcome = $effectOutcome;
                                    $error = $effectError;
                                    break 2;
                                }

                                $records[] = new ToolCall(
                                    id: 'rec_' . Id::generate(),
                                    sequence: count($records) + 1,
                                    at: new \DateTimeImmutable(),
                                    callId: $cue->effectId,
                                    toolName: $cue->effectId,
                                    arguments: $cue->arguments,
                                );
                                $records[] = new ToolResult(
                                    id: 'rec_' . Id::generate(),
                                    sequence: count($records) + 1,
                                    at: new \DateTimeImmutable(),
                                    callId: $cue->effectId,
                                    output: is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR),
                                );
                            } else {
                                $outcome = Outcome::Failed;
                                $error = new \RuntimeException("No effect handler for {$cue->effectId}");
                                break 2;
                            }
                        }
                    }

                    if ($text !== '') {
                        $records[] = new Message(
                            id: 'msg_' . Id::generate(),
                            sequence: count($records) + 1,
                            at: new \DateTimeImmutable(),
                            role: 'assistant',
                            text: $text,
                        );
                    }

                    $invocationCount = $i;

                    $current = Log::from($records);
                    $afterCtx = StepContext::afterInvocation($turnConfig, $current, $invocation, $outcome);
                    $hookResult = $chain->notify($producerScope, $afterCtx);
                    if ($hookResult->outcome->terminal()) {
                        $outcome = $hookResult->outcome;
                        $error = $hookResult->error;
                    }

                    if ($outcome->terminal()) {
                        break;
                    }
                }

                if ($invocationCount === 0) {
                    $invocationCount = 1;
                }

                if (!$outcome->terminal()) {
                    $outcome = Outcome::MaxInvocationsReached;
                }

                $terminalHolder[0] = new ProofTerminalState(
                    outcome: $outcome,
                    log: Log::from($records),
                    invocations: $invocationCount,
                    error: $error,
                );
            } catch (Cancelled $e) {
                $cueChannel->error($e);
                $terminalHolder[0] = new ProofTerminalState(
                    outcome: Outcome::Cancelled,
                    log: Log::from($records),
                    invocations: $invocationCount,
                    error: $e,
                );
            } catch (\Throwable $e) {
                $cueChannel->error($e);
                $terminalHolder[0] = new ProofTerminalState(
                    outcome: Outcome::Failed,
                    log: Log::from($records),
                    invocations: $invocationCount,
                    error: $e,
                );
            } finally {
                if ($cueChannel->isOpen) {
                    $cueChannel->complete();
                }
            }
        }, 'athena.channel-loop.' . $config->id);

        return new ProofLazyResult(
            activityId: $config->id,
            stream: Stream::from($cueChannel->consume()),
            terminalHolder: $terminalHolder,
        );
    }
}
