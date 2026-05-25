<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Template\Screen;

use Phalanx\Harness\Template\AppStore;
use Phalanx\Harness\Template\Screen\LlmRequestDetailScreen;
use Phalanx\Harness\Template\Slice\LlmRequestEntry;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\InputElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Text\Line;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LlmRequestDetailScreenTest extends TestCase
{
    #[Test]
    public function rendersSelectedRequestAndResponseBodies(): void
    {
        $store = $this->storeWithRequest();
        $screen = new LlmRequestDetailScreen($store);

        $result = $screen($this->makeContext());

        self::assertInstanceOf(ColumnElement::class, $result);

        $text = self::flatten($result);
        self::assertStringContainsString('POST /api/chat', $text);
        self::assertStringContainsString('Status:', $text);
        self::assertStringContainsString('200 OK', $text);
        self::assertStringContainsString('Request Body', $text);
        self::assertStringContainsString('"model": "qwen3:4b"', $text);
        self::assertStringContainsString('Response Body', $text);
        self::assertStringContainsString('"content": "Strategic Guidance"', $text);
    }

    #[Test]
    public function rendersPinnedRequestInsteadOfLatestFocusedRequest(): void
    {
        $store = new AppStore();
        $store->requests = $store->requests
            ->append(new LlmRequestEntry(
                requestId: 'req-1',
                method: 'POST',
                path: '/api/first',
                status: 200,
                requestBody: '{"message":"first"}',
                responseBody: '{"content":"first response"}',
            ))
            ->selectFocusedForDetail()
            ->append(new LlmRequestEntry(
                requestId: 'req-2',
                method: 'POST',
                path: '/api/second',
                status: 200,
                requestBody: '{"message":"second"}',
                responseBody: '{"content":"second response"}',
            ));
        $screen = new LlmRequestDetailScreen($store);

        $text = self::flatten($screen($this->makeContext()));

        self::assertStringContainsString('POST /api/first', $text);
        self::assertStringContainsString('"message": "first"', $text);
        self::assertStringContainsString('"content": "first response"', $text);
        self::assertStringNotContainsString('POST /api/second', $text);
    }

    #[Test]
    public function rendersEmptyStateWhenSelectedRequestLeavesHistory(): void
    {
        $store = new AppStore();
        $store->requests = $store->requests
            ->append(new LlmRequestEntry('req-1', 'POST', '/api/first'))
            ->selectFocusedForDetail();

        for ($i = 2; $i <= 52; $i++) {
            $store->requests = $store->requests->append(new LlmRequestEntry("req-{$i}", 'POST', "/api/{$i}"));
        }

        $screen = new LlmRequestDetailScreen($store);

        self::assertSame('  No request selected.', self::flatten($screen($this->makeContext())));
    }

    #[Test]
    public function scrollKeysMoveDetailOffset(): void
    {
        $store = $this->storeWithRequest();
        $screen = new LlmRequestDetailScreen($store);

        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Down)));
        self::assertSame(3, $store->requests->detailScrollOffset);

        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Up)));
        self::assertSame(0, $store->requests->detailScrollOffset);
    }

    #[Test]
    public function statusBarRendersDetailControls(): void
    {
        $screen = new LlmRequestDetailScreen(new AppStore());

        $text = self::flatten($screen->statusBar());

        self::assertStringContainsString('Up scroll', $text);
        self::assertStringContainsString('Dn scroll', $text);
        self::assertStringContainsString('Esc back', $text);
    }

    private static function flatten(Renderable|string $renderable): string
    {
        if (is_string($renderable)) {
            return $renderable;
        }

        if ($renderable instanceof TextElement) {
            return self::lineToText($renderable->content);
        }

        if ($renderable instanceof InputElement) {
            return self::lineToText($renderable->prompt) . $renderable->value;
        }

        if ($renderable instanceof ColumnElement || $renderable instanceof RowElement) {
            return implode("\n", array_map(self::flatten(...), $renderable->children));
        }

        if ($renderable instanceof PanelElement) {
            return self::lineToText($renderable->title) . "\n" . self::flatten($renderable->child);
        }

        return '';
    }

    private static function lineToText(string|Line $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return implode('', array_map(static fn($span): string => $span->content, $content->spans));
    }

    private function storeWithRequest(): AppStore
    {
        $store = new AppStore();
        $store->requests = $store->requests->append(new LlmRequestEntry(
            requestId: 'req-1',
            method: 'POST',
            path: '/api/chat',
            status: 200,
            elapsedMs: 39_497.0,
            tokenCount: 312,
            requestBody: '{"model":"qwen3:4b","messages":[{"role":"user","content":"adding a couple messages"}]}',
            responseBody: '{"content":"Strategic Guidance"}',
            complete: true,
        ));

        return $store;
    }

    private function makeContext(): ScreenContext
    {
        $scope = $this->createStub(TaskScope::class);
        $navigator = $this->createStub(Navigator::class);
        $mountSystem = new MountSystem($scope);

        return new ScreenContext($scope, Theme::default(), $navigator, $mountSystem);
    }
}
