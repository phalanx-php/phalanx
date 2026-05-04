<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Acme\DataAnalyst;
use Acme\ResearchAgent;
use Acme\SummarizationAgent;
use Acme\Tools\CrossReference;
use Acme\Tools\ExtractDocumentContent;
use Acme\Tools\QuerySpreadsheet;
use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\Tool\Disposition;
use Phalanx\Athena\Tool\SchemaGenerator;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Athena\Turn;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Retryable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResearchAgentExampleTest extends TestCase
{
    #[Test]
    public function research_agent_implements_retryable_and_timeout(): void
    {
        $agent = new ResearchAgent();

        $this->assertInstanceOf(AgentDefinition::class, $agent);
        $this->assertInstanceOf(Retryable::class, $agent);
        $this->assertInstanceOf(HasTimeout::class, $agent);
    }

    #[Test]
    public function research_agent_has_60_second_timeout(): void
    {
        $agent = new ResearchAgent();

        $this->assertSame(60.0, $agent->timeout);
    }

    #[Test]
    public function research_agent_uses_exponential_retry(): void
    {
        $agent = new ResearchAgent();

        $this->assertSame(2, $agent->retryPolicy->attempts);
        $this->assertSame('exponential', $agent->retryPolicy->strategy);
    }

    #[Test]
    public function research_agent_declares_three_tools(): void
    {
        $agent = new ResearchAgent();
        $tools = $agent->tools();

        $this->assertCount(3, $tools);
        $this->assertContains(ExtractDocumentContent::class, $tools);
        $this->assertContains(QuerySpreadsheet::class, $tools);
        $this->assertContains(CrossReference::class, $tools);
    }

    #[Test]
    public function sub_agents_have_no_tools(): void
    {
        $summarizer = new SummarizationAgent();
        $analyst = new DataAnalyst();

        $this->assertEmpty($summarizer->tools());
        $this->assertEmpty($analyst->tools());
    }

    #[Test]
    public function sub_agents_use_default_provider(): void
    {
        $summarizer = new SummarizationAgent();
        $analyst = new DataAnalyst();

        $this->assertNull($summarizer->provider());
        $this->assertNull($analyst->provider());
    }

    #[Test]
    public function extract_document_tool_has_timeout(): void
    {
        $this->assertTrue(is_subclass_of(ExtractDocumentContent::class, HasTimeout::class)
            || in_array(HasTimeout::class, class_implements(ExtractDocumentContent::class)));
    }

    #[Test]
    public function extract_document_schema_requires_path_and_focus(): void
    {
        $schema = SchemaGenerator::generate(ExtractDocumentContent::class);

        $this->assertSame('extract_document_content', $schema['name']);
        $this->assertContains('documentPath', $schema['input_schema']['required']);
        $this->assertContains('focus', $schema['input_schema']['required']);
    }

    #[Test]
    public function extract_document_returns_summary_data(): void
    {
        $tool = new ExtractDocumentContent('/uploads/q1.pdf', 'revenue trends');
        /** @var \Phalanx\Scope\Scope&\PHPUnit\Framework\MockObject\MockObject $scope */
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);

        $outcome = $tool($scope);

        $this->assertSame(Disposition::Continue, $outcome->disposition);
        $this->assertSame('/uploads/q1.pdf', $outcome->data['document']);
        $this->assertSame('revenue trends', $outcome->data['focus']);
        $this->assertArrayHasKey('key_points', $outcome->data);
    }

    #[Test]
    public function cross_reference_schema_requires_question_and_document_ids(): void
    {
        $schema = SchemaGenerator::generate(CrossReference::class);

        $this->assertSame('cross_reference', $schema['name']);
        $this->assertContains('question', $schema['input_schema']['required']);
        $this->assertContains('documentIds', $schema['input_schema']['required']);
    }

    #[Test]
    public function cross_reference_returns_findings(): void
    {
        $tool = new CrossReference('Compare revenue', ['doc1', 'doc2']);
        /** @var \Phalanx\Scope\Scope&\PHPUnit\Framework\MockObject\MockObject $scope */
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);

        $outcome = $tool($scope);

        $this->assertSame(Disposition::Continue, $outcome->disposition);
        $this->assertSame('Compare revenue', $outcome->data['question']);
        $this->assertCount(2, $outcome->data['sources']);
    }

    #[Test]
    public function query_spreadsheet_returns_result(): void
    {
        $tool = new QuerySpreadsheet('/uploads/forecast.csv', 'total Q3 revenue');
        /** @var \Phalanx\Scope\Scope&\PHPUnit\Framework\MockObject\MockObject $scope */
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);

        $outcome = $tool($scope);

        $this->assertSame(Disposition::Continue, $outcome->disposition);
        $this->assertSame('/uploads/forecast.csv', $outcome->data['file']);
    }

    #[Test]
    public function tool_registry_resolves_all_research_tools(): void
    {
        $agent = new ResearchAgent();
        $registry = ToolRegistry::from($agent->tools());
        $schemas = $registry->allSchemas();

        $this->assertCount(3, $schemas);

        $names = array_column($schemas, 'name');
        $this->assertContains('extract_document_content', $names);
        $this->assertContains('query_spreadsheet', $names);
        $this->assertContains('cross_reference', $names);
    }

    #[Test]
    public function turn_builds_with_multi_doc_question(): void
    {
        $turn = Turn::begin(new ResearchAgent())
            ->message("Documents:\n- Q1.pdf\n- Q2.pdf\n\nCompare revenue trends.")
            ->maxSteps(8);

        $conv = $turn->buildConversation();

        $this->assertStringContainsString('research analyst', $conv->systemPrompt);
        $this->assertSame(1, $conv->count());
        $this->assertSame(8, $turn->maxSteps);
    }
}
