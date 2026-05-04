<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Acme\TenantAgentFactory;
use Acme\TenantSupportAgent;
use Acme\Tools\TenantKbSearch;
use Acme\Tools\TransferToHuman;
use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\Tool\Disposition;
use Phalanx\Athena\Tool\SchemaGenerator;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Athena\Turn;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MultiTenantFleetExampleTest extends TestCase
{
    #[Test]
    public function factory_creates_agent_with_default_config(): void
    {
        $factory = new TenantAgentFactory();
        $agent = $factory->create('tenant_unknown');

        $this->assertInstanceOf(AgentDefinition::class, $agent);
        $this->assertInstanceOf(TenantSupportAgent::class, $agent);
        $this->assertSame('anthropic', $agent->provider());
    }

    #[Test]
    public function factory_creates_agent_with_custom_config(): void
    {
        $factory = new TenantAgentFactory([
            'acme_corp' => [
                'system_prompt' => 'You are the Acme Corp support bot.',
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'enabled_tools' => ['knowledge_base'],
                'escalation' => ['threshold' => 3],
            ],
        ]);

        $agent = $factory->create('acme_corp');

        $this->assertSame('openai', $agent->provider());
        $this->assertStringContainsString('Acme Corp', $agent->instructions);
    }

    #[Test]
    public function factory_resolves_enabled_tools(): void
    {
        $factory = new TenantAgentFactory([
            'tenant_a' => [
                'system_prompt' => 'Help.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-20250514',
                'enabled_tools' => ['knowledge_base', 'transfer_to_human'],
                'escalation' => [],
            ],
        ]);

        $agent = $factory->create('tenant_a');
        $tools = $agent->tools();

        $this->assertCount(2, $tools);
        $this->assertContains(TenantKbSearch::class, $tools);
        $this->assertContains(TransferToHuman::class, $tools);
    }

    #[Test]
    public function factory_resolves_subset_of_tools(): void
    {
        $factory = new TenantAgentFactory([
            'limited' => [
                'system_prompt' => 'KB only.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-20250514',
                'enabled_tools' => ['knowledge_base'],
                'escalation' => [],
            ],
        ]);

        $agent = $factory->create('limited');

        $this->assertCount(1, $agent->tools());
        $this->assertContains(TenantKbSearch::class, $agent->tools());
    }

    #[Test]
    public function transfer_to_human_terminates_agent_loop(): void
    {
        $tool = new TransferToHuman(
            reason: 'Customer wants refund over $500',
            department: 'billing',
            contextSummary: 'Customer asked about refund policy for enterprise plan',
        );

        /** @var \Phalanx\Scope&\PHPUnit\Framework\MockObject\MockObject $scope */
        $scope = $this->createMock(\Phalanx\Scope::class);
        $outcome = $tool($scope);

        $this->assertSame(Disposition::Terminate, $outcome->disposition);
        $this->assertStringContainsString('billing specialist', $outcome->data);
        $this->assertSame('Transferred to human agent', $outcome->reason);
    }

    #[Test]
    public function transfer_to_human_schema_requires_all_params(): void
    {
        $schema = SchemaGenerator::generate(TransferToHuman::class);

        $this->assertSame('transfer_to_human', $schema['name']);
        $this->assertContains('reason', $schema['input_schema']['required']);
        $this->assertContains('department', $schema['input_schema']['required']);
        $this->assertContains('contextSummary', $schema['input_schema']['required']);
    }

    #[Test]
    public function tenant_kb_search_returns_articles(): void
    {
        $tool = new TenantKbSearch('how to export', 3);
        /** @var \Phalanx\Scope&\PHPUnit\Framework\MockObject\MockObject $scope */
        $scope = $this->createMock(\Phalanx\Scope::class);

        $outcome = $tool($scope);

        $this->assertSame(Disposition::Continue, $outcome->disposition);
        $this->assertArrayHasKey('articles', $outcome->data);
        $this->assertNotEmpty($outcome->data['articles']);
    }

    #[Test]
    public function tenant_kb_search_schema_has_optional_limit(): void
    {
        $schema = SchemaGenerator::generate(TenantKbSearch::class);

        $this->assertContains('query', $schema['input_schema']['required']);
        $this->assertNotContains('limit', $schema['input_schema']['required']);
        $this->assertSame(3, $schema['input_schema']['properties']['limit']['default']);
    }

    #[Test]
    public function tool_registry_resolves_fleet_tools(): void
    {
        $registry = ToolRegistry::from([TenantKbSearch::class, TransferToHuman::class]);
        $schemas = $registry->allSchemas();

        $this->assertCount(2, $schemas);

        $names = array_column($schemas, 'name');
        $this->assertContains('tenant_kb_search', $names);
        $this->assertContains('transfer_to_human', $names);
    }

    #[Test]
    public function tool_registry_hydrates_transfer_to_human(): void
    {
        $registry = ToolRegistry::from([TransferToHuman::class]);
        $call = new \Phalanx\Athena\Tool\ToolCall('tc_1', 'transfer_to_human', [
            'reason' => 'Cannot resolve',
            'department' => 'technical',
            'contextSummary' => 'DNS issue',
        ]);

        $tool = $registry->hydrate($call);

        $this->assertInstanceOf(TransferToHuman::class, $tool);

        /** @var \Phalanx\Scope&\PHPUnit\Framework\MockObject\MockObject $scope */
        $scope = $this->createMock(\Phalanx\Scope::class);
        $outcome = $tool($scope);

        $this->assertSame(Disposition::Terminate, $outcome->disposition);
        $this->assertStringContainsString('technical specialist', $outcome->data);
    }

    #[Test]
    public function tenant_agent_turn_builds_correctly(): void
    {
        $factory = new TenantAgentFactory();
        $agent = $factory->create('test_tenant');

        $turn = Turn::begin($agent)
            ->message('I need help with my subscription')
            ->maxSteps(6);

        $conv = $turn->buildConversation();

        $this->assertNotNull($conv->systemPrompt);
        $this->assertSame(1, $conv->count());
        $this->assertSame(6, $turn->maxSteps);
    }

    #[Test]
    public function different_tenants_get_different_agents(): void
    {
        $factory = new TenantAgentFactory([
            'tenant_a' => [
                'system_prompt' => 'You are Tenant A support.',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-20250514',
                'enabled_tools' => ['knowledge_base'],
                'escalation' => [],
            ],
            'tenant_b' => [
                'system_prompt' => 'You are Tenant B support.',
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'enabled_tools' => ['transfer_to_human'],
                'escalation' => [],
            ],
        ]);

        $agentA = $factory->create('tenant_a');
        $agentB = $factory->create('tenant_b');

        $this->assertStringContainsString('Tenant A', $agentA->instructions);
        $this->assertStringContainsString('Tenant B', $agentB->instructions);
        $this->assertSame('anthropic', $agentA->provider());
        $this->assertSame('openai', $agentB->provider());
        $this->assertCount(1, $agentA->tools());
        $this->assertCount(1, $agentB->tools());
        $this->assertContains(TenantKbSearch::class, $agentA->tools());
        $this->assertContains(TransferToHuman::class, $agentB->tools());
    }
}
