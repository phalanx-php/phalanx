<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Ui\Slices;

use Phalanx\Harness\Ui\Slices\LlmRequestEntry;
use Phalanx\Harness\Ui\Slices\LlmRequestSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LlmRequestSliceTest extends TestCase
{
    #[Test]
    public function selectingFocusedRequestPinsDetailSelection(): void
    {
        $slice = new LlmRequestSlice()
            ->append($this->request('req-1', '/first'))
            ->append($this->request('req-2', '/second'))
            ->focusUp()
            ->selectFocusedForDetail();

        self::assertSame('req-1', $slice->selectedRequestId);
        self::assertSame('/first', $slice->selected()?->path);
    }

    #[Test]
    public function appendingNewRequestsKeepsDetailSelectionPinned(): void
    {
        $slice = new LlmRequestSlice()
            ->append($this->request('req-1', '/first'))
            ->selectFocusedForDetail()
            ->append($this->request('req-2', '/second'));

        self::assertSame(1, $slice->focusedIndex);
        self::assertSame('/second', $slice->focused()?->path);
        self::assertSame('/first', $slice->selected()?->path);
    }

    #[Test]
    public function selectedFallsBackToFocusedBeforeDetailSelection(): void
    {
        $slice = new LlmRequestSlice()
            ->append($this->request('req-1', '/first'))
            ->append($this->request('req-2', '/second'));

        self::assertSame('/second', $slice->focused()?->path);
        self::assertSame('/second', $slice->selected()?->path);
    }

    #[Test]
    public function selectedReturnsNullWhenPinnedRequestLeavesHistory(): void
    {
        $slice = new LlmRequestSlice()
            ->append($this->request('req-1', '/first'))
            ->selectFocusedForDetail();

        for ($i = 2; $i <= 52; $i++) {
            $slice = $slice->append($this->request("req-{$i}", "/req/{$i}"));
        }

        self::assertSame('req-1', $slice->selectedRequestId);
        self::assertNull($slice->selected());
    }

    private function request(string $id, string $path): LlmRequestEntry
    {
        return new LlmRequestEntry(
            requestId: $id,
            method: 'POST',
            path: $path,
        );
    }
}
