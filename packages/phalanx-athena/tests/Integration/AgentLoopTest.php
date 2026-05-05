<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentResult;
use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Message\Message;
use Phalanx\Athena\StepAction;
use Phalanx\Athena\StepActionKind;
use Phalanx\Athena\StepResult;
use Phalanx\Athena\Tool\Disposition;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Athena\Turn;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentLoopTest extends TestCase
{
    #[Test]
    public function turn_is_immutable(): void
    {
        $agent = new SimpleTestAgent();
        $t1 = Turn::begin($agent);
        $t2 = $t1->message('hello');
        $t3 = $t2->maxSteps(5);
        $t4 = $t3->output(\stdClass::class);
        $t5 = $t4->stream();

        $this->assertNotSame($t1, $t2);
        $this->assertNotSame($t2, $t3);
        $this->assertNotSame($t3, $t4);
        $this->assertNotSame($t4, $t5);

        $this->assertSame(10, $t1->maxSteps);
        $this->assertSame(5, $t3->maxSteps);
        $this->assertFalse($t4->streaming);
        $this->assertTrue($t5->streaming);
    }

    #[Test]
    public function turn_builds_conversation_with_system_prompt(): void
    {
        $agent = new SimpleTestAgent();
        $turn = Turn::begin($agent)
            ->message('Hello');

        $conv = $turn->buildConversation();

        $this->assertSame('You are helpful.', $conv->systemPrompt);
        $this->assertSame(1, $conv->count());
    }

    #[Test]
    public function turn_preserves_existing_conversation(): void
    {
        $agent = new SimpleTestAgent();
        $existing = Conversation::create()
            ->system('Custom system prompt')
            ->user('Previous message');

        $turn = Turn::begin($agent)
            ->conversation($existing)
            ->message('New message');

        $conv = $turn->buildConversation();

        $this->assertSame('Custom system prompt', $conv->systemPrompt);
        $this->assertSame(2, $conv->count());
    }

    #[Test]
    public function turn_on_step_hook(): void
    {
        $agent = new SimpleTestAgent();
        $called = false;

        $turn = Turn::begin($agent)
            ->onStep(static function (StepResult $step) use (&$called): StepAction {
                $called = true;
                return StepAction::continue();
            });

        $this->assertNotNull($turn->onStepHook);
    }

    #[Test]
    public function step_action_finalize(): void
    {
        $action = StepAction::finalize('Final answer');

        $this->assertSame(StepActionKind::Finalize, $action->kind);
        $this->assertSame('Final answer', $action->finalText);
    }

    #[Test]
    public function step_action_inject(): void
    {
        $msg = Message::system('One step remaining');
        $action = StepAction::inject($msg);

        $this->assertSame(StepActionKind::Inject, $action->kind);
        $this->assertSame($msg, $action->message);
    }

    #[Test]
    public function step_action_continue(): void
    {
        $action = StepAction::continue();

        $this->assertSame(StepActionKind::Continue, $action->kind);
        $this->assertNull($action->message);
        $this->assertNull($action->finalText);
    }

    #[Test]
    public function agent_result_from_generation_data(): void
    {
        $usage = new TokenUsage(input: 100, output: 50);
        $conv = Conversation::create()->user('test');

        $result = AgentResult::maxStepsReached($conv, $usage, 3);

        $this->assertSame('', $result->text);
        $this->assertSame(3, $result->steps);
        $this->assertSame(150, $result->usage->total);
    }

    #[Test]
    public function agent_result_to_array(): void
    {
        $usage = new TokenUsage(input: 100, output: 50);
        $conv = Conversation::create();

        $result = new AgentResult('Hello', null, $conv, $usage, 1);
        $arr = $result->toArray();

        $this->assertSame('Hello', $arr['text']);
        $this->assertSame(100, $arr['usage']['input']);
        $this->assertSame(50, $arr['usage']['output']);
        $this->assertSame(150, $arr['usage']['total']);
        $this->assertSame(1, $arr['steps']);
    }

    #[Test]
    public function token_usage_arithmetic(): void
    {
        $a = new TokenUsage(input: 10, output: 5);
        $b = new TokenUsage(input: 20, output: 15);

        $sum = $a->add($b);

        $this->assertSame(30, $sum->input);
        $this->assertSame(20, $sum->output);
        $this->assertSame(50, $sum->total);
    }

    #[Test]
    public function token_usage_zero(): void
    {
        $zero = TokenUsage::zero();

        $this->assertSame(0, $zero->input);
        $this->assertSame(0, $zero->output);
        $this->assertSame(0, $zero->total);
    }

    #[Test]
    public function token_usage_serialization(): void
    {
        $usage = new TokenUsage(input: 42, output: 18);
        $arr = $usage->toArray();
        $restored = TokenUsage::fromArray($arr);

        $this->assertSame(42, $restored->input);
        $this->assertSame(18, $restored->output);
    }

    #[Test]
    public function agent_event_serialization(): void
    {
        $event = AgentEvent::llmStart(1, 123.45);
        $json = $event->toJson();
        $restored = AgentEvent::fromJson($json);

        $this->assertSame(AgentEventKind::LlmStart, $restored->kind);
        $this->assertSame(1, $restored->step);
        $this->assertEqualsWithDelta(123.45, $restored->elapsed, 0.01);
    }

    #[Test]
    public function agent_event_kind_user_facing(): void
    {
        $this->assertTrue(AgentEventKind::TokenDelta->isUserFacing());
        $this->assertTrue(AgentEventKind::ToolCallStart->isUserFacing());
        $this->assertTrue(AgentEventKind::AgentComplete->isUserFacing());
        $this->assertFalse(AgentEventKind::LlmStart->isUserFacing());
        $this->assertFalse(AgentEventKind::StepComplete->isUserFacing());
    }

    #[Test]
    public function agent_event_kind_is_tool(): void
    {
        $this->assertTrue(AgentEventKind::ToolCallStart->isTool());
        $this->assertTrue(AgentEventKind::ToolCallComplete->isTool());
        $this->assertFalse(AgentEventKind::TokenDelta->isTool());
    }

    #[Test]
    public function tool_outcome_data(): void
    {
        $outcome = ToolOutcome::data(['key' => 'value']);

        $this->assertSame(Disposition::Continue, $outcome->disposition);
        $this->assertSame(['key' => 'value'], $outcome->data);
    }

    #[Test]
    public function tool_outcome_done(): void
    {
        $outcome = ToolOutcome::done('finished', reason: 'task complete');

        $this->assertSame(Disposition::Terminate, $outcome->disposition);
        $this->assertSame('finished', $outcome->data);
        $this->assertSame('task complete', $outcome->reason);
    }

    #[Test]
    public function tool_outcome_escalate(): void
    {
        $outcome = ToolOutcome::escalate('Needs human review');

        $this->assertSame(Disposition::Escalate, $outcome->disposition);
        $this->assertSame('Needs human review', $outcome->reason);
    }

    #[Test]
    public function tool_outcome_retry(): void
    {
        $outcome = ToolOutcome::retry('Try with different parameters');

        $this->assertSame(Disposition::Retry, $outcome->disposition);
        $this->assertSame('Try with different parameters', $outcome->reason);
    }
}

/** @internal */
final class SimpleTestAgent implements AgentDefinition
{
    public string $instructions {
        get => 'You are helpful.';
    }

    public function __invoke(\Phalanx\ExecutionScope $scope): mixed
    {
        return null;
    }

    public function tools(): array
    {
        return [];
    }

    public function provider(): ?string
    {
        return null;
    }
}
