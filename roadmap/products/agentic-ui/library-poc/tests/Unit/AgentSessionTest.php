<?php

declare(strict_types=1);

namespace Phalanx\Tests\Agentic\Unit;

use Phalanx\Agentic\AgentSession\AgentSession;
use Phalanx\Agentic\AgentSession\SessionConfig;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentSessionTest extends TestCase
{
    private AgentSession $session;

    #[After]
    public function cleanup(): void
    {
        // In real PhalanxTestCase this would be handled automatically
        // For POC we manually verify state
    }

    #[Test]
    public function it_starts_in_idle_state(): void
    {
        $config = new SessionConfig('test-1', 'TestAgent::class');
        $this->session = new AgentSession(
            $config,
            $this->createStub(\Phalanx\Athena\Memory\ConversationMemory::class),
            $this->createStub(\Phalanx\Athena\AgentDefinition::class)
        );

        $state = $this->session->getState();
        $this->assertSame('idle', $state->status());
    }

    #[Test]
    public function cancel_transitions_state_and_records_reason(): void
    {
        $config = new SessionConfig('test-2', 'TestAgent::class');
        $this->session = new AgentSession(
            $config,
            $this->createStub(\Phalanx\Athena\Memory\ConversationMemory::class),
            $this->createStub(\Phalanx\Athena\AgentDefinition::class)
        );

        $this->session->cancel('user_initiated');
        $state = $this->session->getState();

        $this->assertSame('cancelled', $state->status());
        $this->assertSame('user_initiated', $state->lastSignal()['reason'] ?? null);
    }
}
