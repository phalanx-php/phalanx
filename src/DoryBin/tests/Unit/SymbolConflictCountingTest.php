<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the chunk-boundary symbol counting algorithm
 * used in SymbolConflictCheck. The algorithm processes nm output in
 * chunks and must correctly count symbols that span chunk boundaries
 * without double-counting.
 */
final class SymbolConflictCountingTest extends TestCase
{
    #[Test]
    public function symbol_fully_within_single_chunk(): void
    {
        $chunks = ['some_prefix curl_share_ce some_suffix'];

        self::assertSame(1, self::countInChunks($chunks, 'curl_share_ce'));
    }

    #[Test]
    public function symbol_absent_from_output(): void
    {
        $chunks = ['no matching symbols here', 'or here either'];

        self::assertSame(0, self::countInChunks($chunks, 'curl_share_ce'));
    }

    #[Test]
    public function multiple_occurrences_in_single_chunk(): void
    {
        $chunks = ['curl_share_ce foo curl_share_ce bar curl_share_ce'];

        self::assertSame(3, self::countInChunks($chunks, 'curl_share_ce'));
    }

    #[Test]
    public function symbol_spanning_chunk_boundary_counted_once(): void
    {
        $chunks = ['prefix curl_sha', 're_ce suffix'];

        self::assertSame(1, self::countInChunks($chunks, 'curl_share_ce'));
    }

    #[Test]
    public function symbol_at_end_of_first_chunk_not_double_counted(): void
    {
        $chunks = ['prefix curl_share_ce', ' next chunk data'];

        self::assertSame(1, self::countInChunks($chunks, 'curl_share_ce'));
    }

    #[Test]
    public function symbol_at_start_of_second_chunk_not_double_counted(): void
    {
        $chunks = ['prefix data ', 'curl_share_ce suffix'];

        self::assertSame(1, self::countInChunks($chunks, 'curl_share_ce'));
    }

    #[Test]
    public function multiple_symbols_across_multiple_chunks(): void
    {
        $chunks = [
            'curl_share_ce first',
            ' middle curl_share_ce',
            ' end curl_share_ce done',
        ];

        self::assertSame(3, self::countInChunks($chunks, 'curl_share_ce'));
    }

    #[Test]
    public function symbol_split_across_three_chunks(): void
    {
        $chunks = ['curl_', 'share', '_ce'];

        self::assertSame(1, self::countInChunks($chunks, 'curl_share_ce'));
    }

    #[Test]
    public function small_chunks_one_byte_at_a_time(): void
    {
        $input = 'xcurl_share_cey';
        $chunks = str_split($input, 1);

        self::assertSame(1, self::countInChunks($chunks, 'curl_share_ce'));
    }

    #[Test]
    public function two_symbols_with_boundary_split(): void
    {
        $chunks = ['curl_share_ce curl_sha', 're_ce'];

        self::assertSame(2, self::countInChunks($chunks, 'curl_share_ce'));
    }

    /**
     * Reimplements the counting algorithm from SymbolConflictCheck::countSymbol()
     * to verify the boundary logic in isolation without needing StreamingProcess.
     *
     * @param list<string> $chunks
     */
    private static function countInChunks(array $chunks, string $symbol): int
    {
        $count = 0;
        $tail = '';

        foreach ($chunks as $chunk) {
            $count += substr_count($tail . $chunk, $symbol) - substr_count($tail, $symbol);
            $tail = strlen($chunk) >= strlen($symbol)
                ? substr($chunk, -strlen($symbol) + 1)
                : substr($tail . $chunk, -strlen($symbol) + 1);
        }

        return $count;
    }
}
