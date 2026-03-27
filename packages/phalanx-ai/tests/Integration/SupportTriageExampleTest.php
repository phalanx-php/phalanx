<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tests\Integration;

use Acme\SupportTriageAgent;
use Acme\TicketCategory;
use Acme\TicketPriority;
use Acme\Tools\CheckServiceStatus;
use Acme\Tools\GetRecentTickets;
use Acme\Tools\LookupCustomer;
use Acme\Tools\SearchKnowledgeBase;
use Acme\TriageResult;
use Phalanx\Ai\AgentDefinition;
use Phalanx\Ai\Schema\StructuredOutputParser;
use Phalanx\Ai\Tool\Disposition;
use Phalanx\Ai\Tool\SchemaGenerator;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Ai\Tool\ToolRegistry;
use Phalanx\Ai\Turn;
use Phalanx\Task\HasTimeout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SupportTriageExampleTest extends TestCase
{
    #[Test]
    public function agent_implements_required_interfaces(): void
    {
        $agent = new SupportTriageAgent();

        $this->assertInstanceOf(AgentDefinition::class, $agent);
        $this->assertInstanceOf(HasTimeout::class, $agent);
    }

    #[Test]
    public function agent_has_system_prompt(): void
    {
        $agent = new SupportTriageAgent();

        $this->assertStringContainsString('senior support specialist', $agent->instructions);
        $this->assertStringContainsString('priority', $agent->instructions);
        $this->assertStringContainsString('knowledge base', $agent->instructions);
    }

    #[Test]
    public function agent_declares_four_tools(): void
    {
        $agent = new SupportTriageAgent();
        $tools = $agent->tools();

        $this->assertCount(4, $tools);
        $this->assertContains(LookupCustomer::class, $tools);
        $this->assertContains(SearchKnowledgeBase::class, $tools);
        $this->assertContains(GetRecentTickets::class, $tools);
        $this->assertContains(CheckServiceStatus::class, $tools);
    }

    #[Test]
    public function agent_prefers_anthropic_provider(): void
    {
        $agent = new SupportTriageAgent();

        $this->assertSame('anthropic', $agent->provider());
    }

    #[Test]
    public function agent_has_25_second_timeout(): void
    {
        $agent = new SupportTriageAgent();

        $this->assertSame(25.0, $agent->timeout);
    }

    #[Test]
    public function tool_registry_resolves_all_agent_tools(): void
    {
        $agent = new SupportTriageAgent();
        $registry = ToolRegistry::from($agent->tools());
        $schemas = $registry->allSchemas();

        $this->assertCount(4, $schemas);

        $names = array_column($schemas, 'name');
        $this->assertContains('lookup_customer', $names);
        $this->assertContains('search_knowledge_base', $names);
        $this->assertContains('get_recent_tickets', $names);
        $this->assertContains('check_service_status', $names);
    }

    #[Test]
    public function lookup_customer_schema_has_identifier_param(): void
    {
        $schema = SchemaGenerator::generate(LookupCustomer::class);

        $this->assertSame('lookup_customer', $schema['name']);
        $this->assertArrayHasKey('identifier', $schema['input_schema']['properties']);
        $this->assertSame('string', $schema['input_schema']['properties']['identifier']['type']);
        $this->assertContains('identifier', $schema['input_schema']['required']);
    }

    #[Test]
    public function search_kb_schema_has_optional_limit(): void
    {
        $schema = SchemaGenerator::generate(SearchKnowledgeBase::class);

        $this->assertArrayHasKey('limit', $schema['input_schema']['properties']);
        $this->assertSame('integer', $schema['input_schema']['properties']['limit']['type']);
        $this->assertNotContains('limit', $schema['input_schema']['required']);
        $this->assertSame(3, $schema['input_schema']['properties']['limit']['default']);
    }

    #[Test]
    public function lookup_customer_tool_returns_continue_disposition(): void
    {
        $tool = new LookupCustomer('sarah@example.com');
        $scope = $this->createMock(\Phalanx\Scope::class);

        $outcome = $tool($scope);

        $this->assertInstanceOf(ToolOutcome::class, $outcome);
        $this->assertSame(Disposition::Continue, $outcome->disposition);
        $this->assertSame('sarah@example.com', $outcome->data['customer']['email']);
        $this->assertSame(42, $outcome->data['customer']['id']);
    }

    #[Test]
    public function check_service_status_requires_no_params(): void
    {
        $schema = SchemaGenerator::generate(CheckServiceStatus::class);

        $this->assertEmpty($schema['input_schema']['required']);
    }

    #[Test]
    public function check_service_status_returns_operational_data(): void
    {
        $tool = new CheckServiceStatus();
        $scope = $this->createMock(\Phalanx\Scope::class);

        $outcome = $tool($scope);

        $this->assertTrue($outcome->data['all_operational']);
        $this->assertEmpty($outcome->data['active_incidents']);
    }

    #[Test]
    public function tool_registry_hydrates_lookup_customer(): void
    {
        $registry = ToolRegistry::from([LookupCustomer::class]);
        $call = new \Phalanx\Ai\Tool\ToolCall('tc_1', 'lookup_customer', ['identifier' => 'test@test.com']);
        $tool = $registry->hydrate($call);

        $this->assertInstanceOf(LookupCustomer::class, $tool);
    }

    #[Test]
    public function triage_result_schema_generated_from_class(): void
    {
        $schema = StructuredOutputParser::generateSchema(TriageResult::class);

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('priority', $schema['properties']);
        $this->assertArrayHasKey('category', $schema['properties']);
        $this->assertArrayHasKey('draftResponse', $schema['properties']);
        $this->assertArrayHasKey('autoResolvable', $schema['properties']);
        $this->assertContains('priority', $schema['required']);
    }

    #[Test]
    public function triage_result_priority_enum_in_schema(): void
    {
        $schema = StructuredOutputParser::generateSchema(TriageResult::class);

        $this->assertContains('critical', $schema['properties']['priority']['enum']);
        $this->assertContains('high', $schema['properties']['priority']['enum']);
        $this->assertContains('medium', $schema['properties']['priority']['enum']);
        $this->assertContains('low', $schema['properties']['priority']['enum']);
    }

    #[Test]
    public function triage_result_hydrates_from_json(): void
    {
        $json = json_encode([
            'priority' => 'medium',
            'category' => 'billing',
            'summary' => 'Export CSV failing',
            'draftResponse' => 'Hi Sarah, try clearing your cache...',
            'autoResolvable' => false,
            'escalationNote' => null,
            'relevantArticleIds' => [101, 204],
        ]);

        $result = StructuredOutputParser::hydrate(TriageResult::class, $json);

        $this->assertInstanceOf(TriageResult::class, $result);
        $this->assertSame(TicketPriority::Medium, $result->priority);
        $this->assertSame(TicketCategory::Billing, $result->category);
        $this->assertSame('Export CSV failing', $result->summary);
        $this->assertFalse($result->autoResolvable);
        $this->assertSame([101, 204], $result->relevantArticleIds);
    }

    #[Test]
    public function turn_builds_with_triage_agent(): void
    {
        $turn = Turn::begin(new SupportTriageAgent())
            ->message("Ticket from: sarah@example.com\nSubject: Export failing\n\nCSV export times out")
            ->output(TriageResult::class)
            ->maxSteps(4);

        $conv = $turn->buildConversation();

        $this->assertStringContainsString('senior support specialist', $conv->systemPrompt);
        $this->assertSame(1, $conv->count());
        $this->assertSame(4, $turn->maxSteps);
        $this->assertSame(TriageResult::class, $turn->outputClass);
    }
}
