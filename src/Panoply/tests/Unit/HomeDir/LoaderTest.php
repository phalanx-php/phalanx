<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\HomeDir;

use Phalanx\Panoply\HomeDir\Loader;
use Phalanx\Panoply\HomeDir\ValidationError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins spec acceptance for HomeDir YAML loading and validation.
 */
final class LoaderTest extends TestCase
{
    #[Test]
    public function validYamlFileLoads(): void
    {
        $config = Loader::fromFile($this->fixtureFile());

        self::assertSame('claude_code', $config->id);
        self::assertSame('Claude Code', $config->displayName);
    }

    #[Test]
    public function validYamlFileLoadsRoots(): void
    {
        $config = Loader::fromFile($this->fixtureFile());

        self::assertCount(2, $config->roots);
        self::assertSame('~/.claude', $config->roots[0]);
        self::assertSame('~/.claude.json', $config->roots[1]);
    }

    #[Test]
    public function validYamlFileLoadsAdapter(): void
    {
        $config = Loader::fromFile($this->fixtureFile());

        self::assertSame(\Phalanx\Panoply\HomeDir\ClaudeCode\HomeDir::class, $config->adapter);
    }

    #[Test]
    public function fromStringParsesBundledYaml(): void
    {
        $yaml = <<<'YAML'
            id: gemini_cli
            display_name: "Gemini CLI"
            roots:
              - "~/.gemini"
            adapter: "Phalanx\\Panoply\\HomeDir\\GeminiCli\\HomeDir"
            YAML;

        $config = Loader::fromString($yaml);

        self::assertSame('gemini_cli', $config->id);
        self::assertSame('Gemini CLI', $config->displayName);
        self::assertCount(1, $config->roots);
    }

    #[Test]
    public function missingRequiredKeyThrowsValidationError(): void
    {
        $yaml = <<<'YAML'
            display_name: "Missing id"
            roots:
              - "~/.something"
            adapter: "Some\\Class"
            YAML;

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Missing required key: id');

        Loader::fromString($yaml);
    }

    #[Test]
    public function unknownTopLevelKeyThrowsValidationError(): void
    {
        $yaml = <<<'YAML'
            id: test_tool
            display_name: "Test"
            roots:
              - "~/.test"
            adapter: "Some\\Class"
            unknown_field: "not allowed"
            YAML;

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Unknown key: unknown_field');

        Loader::fromString($yaml);
    }

    #[Test]
    public function idMustMatchPattern(): void
    {
        $yaml = <<<'YAML'
            id: "Invalid-Id"
            display_name: "Test"
            roots:
              - "~/.test"
            adapter: "Some\\Class"
            YAML;

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('id must match pattern');

        Loader::fromString($yaml);
    }

    #[Test]
    public function emptyRootsThrowsValidationError(): void
    {
        $yaml = <<<'YAML'
            id: test_tool
            display_name: "Test"
            roots: []
            adapter: "Some\\Class"
            YAML;

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('roots must be a non-empty list');

        Loader::fromString($yaml);
    }

    #[Test]
    public function nonExistentFileThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Loader::fromFile('/does/not/exist/panoply.yaml');
    }

    #[Test]
    public function validationErrorCarriesAllViolations(): void
    {
        $yaml = <<<'YAML'
            display_name: "Missing id and adapter"
            roots:
              - "~/.test"
            YAML;

        try {
            Loader::fromString($yaml, 'test.yaml');
            self::fail('Expected ValidationError');
        } catch (ValidationError $e) {
            self::assertGreaterThanOrEqual(2, count($e->violations));
        }
    }

    private function fixtureFile(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/HomeDir/claudecode.panoply.yaml';
    }
}
