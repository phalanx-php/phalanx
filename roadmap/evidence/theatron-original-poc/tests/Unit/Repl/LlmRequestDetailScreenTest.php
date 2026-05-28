<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Screen\LlmRequestDetailScreen;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LlmRequestDetailScreenTest extends TestCase
{
    #[Test]
    public function render_body_produces_rows_from_valid_json(): void
    {
        $ui = new Ui();
        $rows = [];
        $json = json_encode(['key' => 'value', 'num' => 42], JSON_THROW_ON_ERROR);

        self::invokeRenderBody($ui, $json, $rows);

        self::assertNotEmpty($rows);
        self::assertContainsOnlyInstancesOf(Renderable::class, $rows);
    }

    #[Test]
    public function render_body_handles_null_body(): void
    {
        $ui = new Ui();
        $rows = [];

        self::invokeRenderBody($ui, null, $rows);

        self::assertCount(1, $rows);
    }

    #[Test]
    public function render_body_handles_non_json_text(): void
    {
        $ui = new Ui();
        $rows = [];

        self::invokeRenderBody($ui, 'this is not json', $rows);

        self::assertNotEmpty($rows);
        self::assertContainsOnlyInstancesOf(Renderable::class, $rows);
    }

    #[Test]
    public function render_body_handles_nested_json(): void
    {
        $ui = new Ui();
        $rows = [];
        $json = json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'hello'],
                ['role' => 'assistant', 'content' => 'hi'],
            ],
            'model' => 'qwen3:4b',
        ], JSON_THROW_ON_ERROR);

        self::invokeRenderBody($ui, $json, $rows);

        self::assertGreaterThan(5, count($rows));
    }

    #[Test]
    public function render_body_handles_empty_json_object(): void
    {
        $ui = new Ui();
        $rows = [];

        self::invokeRenderBody($ui, '{}', $rows);

        self::assertNotEmpty($rows);
    }

    /** @param list<Renderable> &$rows */
    private static function invokeRenderBody(Ui $ui, ?string $body, array &$rows): void
    {
        $method = new \ReflectionMethod(LlmRequestDetailScreen::class, 'renderBody');
        $method->invokeArgs(null, [$ui, $body, &$rows]);
    }
}
