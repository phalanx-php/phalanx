<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Athena\Schema\StructuredOutputParser;
use Phalanx\Athena\Tests\Fixtures\CalculatorTool;
use Phalanx\Athena\Tests\Fixtures\EchoTool;
use Phalanx\Athena\Tests\Fixtures\SentimentKind;
use Phalanx\Athena\Tests\Fixtures\SentimentResult;
use Phalanx\Athena\Tool\SchemaGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaGeneratorTest extends TestCase
{
    #[Test]
    public function generates_schema_from_tool_class(): void
    {
        $schema = SchemaGenerator::generate(EchoTool::class);

        $this->assertSame('echo_tool', $schema['name']);
        $this->assertSame('Echoes back the input message', $schema['description']);
        $this->assertSame('object', $schema['input_schema']['type']);
        $this->assertArrayHasKey('message', $schema['input_schema']['properties']);
        $this->assertSame('string', $schema['input_schema']['properties']['message']['type']);
        $this->assertContains('message', $schema['input_schema']['required']);
    }

    #[Test]
    public function generates_schema_with_defaults(): void
    {
        $schema = SchemaGenerator::generate(CalculatorTool::class);

        $this->assertSame('calculator_tool', $schema['name']);
        $this->assertArrayHasKey('a', $schema['input_schema']['properties']);
        $this->assertArrayHasKey('b', $schema['input_schema']['properties']);
        $this->assertArrayHasKey('operation', $schema['input_schema']['properties']);
        $this->assertSame('number', $schema['input_schema']['properties']['a']['type']);
        $this->assertSame('add', $schema['input_schema']['properties']['operation']['default']);
        $this->assertContains('a', $schema['input_schema']['required']);
        $this->assertContains('b', $schema['input_schema']['required']);
        $this->assertNotContains('operation', $schema['input_schema']['required']);
    }

    #[Test]
    public function generates_param_descriptions(): void
    {
        $schema = SchemaGenerator::generate(EchoTool::class);

        $this->assertSame('The message to echo', $schema['input_schema']['properties']['message']['description']);
    }

    #[Test]
    public function caches_generated_schemas(): void
    {
        $schema1 = SchemaGenerator::generate(EchoTool::class);
        $schema2 = SchemaGenerator::generate(EchoTool::class);

        $this->assertSame($schema1, $schema2);
    }

    #[Test]
    public function generates_structured_output_schema(): void
    {
        $schema = StructuredOutputParser::generateSchema(SentimentResult::class);

        $this->assertSame('object', $schema['type']);
        $this->assertSame('Sentiment analysis result', $schema['description']);
        $this->assertArrayHasKey('sentiment', $schema['properties']);
        $this->assertArrayHasKey('confidence', $schema['properties']);
        $this->assertArrayHasKey('reasoning', $schema['properties']);
        $this->assertContains('sentiment', $schema['required']);
        $this->assertSame('string', $schema['properties']['sentiment']['type']);
        $this->assertContains('positive', $schema['properties']['sentiment']['enum']);
        $this->assertContains('negative', $schema['properties']['sentiment']['enum']);
        $this->assertSame('number', $schema['properties']['confidence']['type']);
    }

    #[Test]
    public function hydrates_structured_output(): void
    {
        $json = json_encode([
            'sentiment' => 'positive',
            'confidence' => 0.95,
            'reasoning' => 'Clearly positive language',
        ]);

        /** @var SentimentResult $result */
        $result = StructuredOutputParser::hydrate(SentimentResult::class, $json);

        $this->assertInstanceOf(SentimentResult::class, $result);
        $this->assertSame(SentimentKind::Positive, $result->sentiment);
        $this->assertSame(0.95, $result->confidence);
        $this->assertSame('Clearly positive language', $result->reasoning);
    }

    #[Test]
    public function validates_structured_output_with_missing_field(): void
    {
        $json = json_encode([
            'sentiment' => 'positive',
        ]);

        $errors = StructuredOutputParser::validate(SentimentResult::class, $json);

        $this->assertNotEmpty($errors);
    }
}
