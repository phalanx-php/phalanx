<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Slice\FocusedPane;
use Phalanx\Theatron\Demos\Repl\Slice\FocusedViewSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusedViewSliceTest extends TestCase
{
    #[Test]
    public function slice_key_is_repl_focused(): void
    {
        self::assertSame('repl.focused', (new FocusedViewSlice())->key);
    }

    #[Test]
    public function search_sets_query_and_resets_match_state(): void
    {
        $slice = new FocusedViewSlice(searchMatchIndex: 3, totalMatches: 5);

        $updated = $slice->search('hello');

        self::assertSame('hello', $updated->searchQuery);
        self::assertSame(0, $updated->searchMatchIndex);
        self::assertSame(0, $updated->totalMatches);
    }

    #[Test]
    public function search_is_immutable(): void
    {
        $slice = new FocusedViewSlice();

        $slice->search('test');

        self::assertNull($slice->searchQuery);
    }

    #[Test]
    public function next_match_wraps_around(): void
    {
        $slice = new FocusedViewSlice(searchMatchIndex: 2, totalMatches: 3);

        $next = $slice->nextMatch();

        self::assertSame(0, $next->searchMatchIndex);
    }

    #[Test]
    public function next_match_increments_normally(): void
    {
        $slice = new FocusedViewSlice(searchMatchIndex: 0, totalMatches: 5);

        $next = $slice->nextMatch();

        self::assertSame(1, $next->searchMatchIndex);
    }

    #[Test]
    public function next_match_returns_same_when_no_matches(): void
    {
        $slice = new FocusedViewSlice(totalMatches: 0);

        $result = $slice->nextMatch();

        self::assertSame($slice, $result);
    }

    #[Test]
    public function prev_match_wraps_around(): void
    {
        $slice = new FocusedViewSlice(searchMatchIndex: 0, totalMatches: 4);

        $prev = $slice->prevMatch();

        self::assertSame(3, $prev->searchMatchIndex);
    }

    #[Test]
    public function prev_match_decrements_normally(): void
    {
        $slice = new FocusedViewSlice(searchMatchIndex: 3, totalMatches: 5);

        $prev = $slice->prevMatch();

        self::assertSame(2, $prev->searchMatchIndex);
    }

    #[Test]
    public function prev_match_returns_same_when_no_matches(): void
    {
        $slice = new FocusedViewSlice(totalMatches: 0);

        $result = $slice->prevMatch();

        self::assertSame($slice, $result);
    }

    #[Test]
    public function clear_search_resets_all_search_state(): void
    {
        $slice = new FocusedViewSlice(searchQuery: 'find me', searchMatchIndex: 2, totalMatches: 7);

        $cleared = $slice->clearSearch();

        self::assertNull($cleared->searchQuery);
        self::assertSame(0, $cleared->searchMatchIndex);
        self::assertSame(0, $cleared->totalMatches);
    }

    #[Test]
    public function switch_pane_changes_pane_and_resets_scroll(): void
    {
        $slice = new FocusedViewSlice(scrollPosition: 10, activePane: FocusedPane::Answer);

        $switched = $slice->switchPane(FocusedPane::Question);

        self::assertSame(FocusedPane::Question, $switched->activePane);
        self::assertSame(0, $switched->scrollPosition);
    }

    #[Test]
    public function switch_pane_is_immutable(): void
    {
        $slice = new FocusedViewSlice(activePane: FocusedPane::Answer);

        $slice->switchPane(FocusedPane::Question);

        self::assertSame(FocusedPane::Answer, $slice->activePane);
    }

    #[Test]
    public function toggle_code_blocks_flips_state(): void
    {
        $slice = new FocusedViewSlice(codeBlocksExpanded: false);

        $toggled = $slice->toggleCodeBlocks();

        self::assertTrue($toggled->codeBlocksExpanded);
    }

    #[Test]
    public function toggle_code_blocks_is_immutable(): void
    {
        $slice = new FocusedViewSlice(codeBlocksExpanded: false);

        $slice->toggleCodeBlocks();

        self::assertFalse($slice->codeBlocksExpanded);
    }

    #[Test]
    public function reset_returns_fresh_default_instance(): void
    {
        $slice = new FocusedViewSlice(
            scrollPosition: 15,
            searchQuery: 'something',
            searchMatchIndex: 3,
            totalMatches: 10,
            activePane: FocusedPane::Question,
            codeBlocksExpanded: true,
        );

        $fresh = $slice->reset();

        self::assertSame(0, $fresh->scrollPosition);
        self::assertNull($fresh->searchQuery);
        self::assertSame(0, $fresh->searchMatchIndex);
        self::assertSame(0, $fresh->totalMatches);
        self::assertSame(FocusedPane::Answer, $fresh->activePane);
        self::assertFalse($fresh->codeBlocksExpanded);
    }

    #[Test]
    public function with_match_count_sets_total(): void
    {
        $slice = new FocusedViewSlice();

        $updated = $slice->withMatchCount(42);

        self::assertSame(42, $updated->totalMatches);
    }

    #[Test]
    public function scroll_to_clamps_at_zero(): void
    {
        $slice = new FocusedViewSlice();

        $scrolled = $slice->scrollTo(-5);

        self::assertSame(0, $scrolled->scrollPosition);
    }
}
