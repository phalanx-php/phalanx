<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Reactor\AgentEventBridge;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentEventBridgeTest extends TestCase
{
    #[Test]
    public function detects_diff_result_type(): void
    {
        self::assertSame('diff', AgentEventBridge::detectResultType("--- a/file.php\n+++ b/file.php\n+new line"));
    }

    #[Test]
    public function detects_search_result_type(): void
    {
        self::assertSame('search', AgentEventBridge::detectResultType("1:match line one\n2:match line two"));
    }

    #[Test]
    public function detects_code_result_type(): void
    {
        self::assertSame('code', AgentEventBridge::detectResultType("<?php\n\nfunction hello(): void\n{\n    echo 'hi';\n}"));
    }

    #[Test]
    public function detects_json_result_type(): void
    {
        self::assertSame('json', AgentEventBridge::detectResultType('{"key": "value"}'));
    }

    #[Test]
    public function detects_json_array_result_type(): void
    {
        self::assertSame('json', AgentEventBridge::detectResultType('[1, 2, 3]'));
    }

    #[Test]
    public function detects_plain_text_result_type(): void
    {
        self::assertSame('text', AgentEventBridge::detectResultType('simple output'));
    }

    #[Test]
    public function summarize_arguments_truncates_long_values(): void
    {
        $result = AgentEventBridge::summarizeArguments([
            'command' => 'a very long command that exceeds thirty characters easily',
        ]);

        self::assertStringContainsString('...', $result);
        self::assertLessThanOrEqual(50, mb_strlen($result));
    }

    #[Test]
    public function summarize_arguments_returns_empty_for_empty_array(): void
    {
        self::assertSame('', AgentEventBridge::summarizeArguments([]));
    }

    #[Test]
    public function summarize_arguments_joins_multiple_keys(): void
    {
        $result = AgentEventBridge::summarizeArguments(['a' => '1', 'b' => '2']);

        self::assertSame('a: 1, b: 2', $result);
    }
}
