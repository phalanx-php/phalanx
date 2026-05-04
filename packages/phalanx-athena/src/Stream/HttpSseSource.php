<?php

declare(strict_types=1);

namespace Phalanx\Athena\Stream;

use Generator;
use Phalanx\Scope\Suspendable;
use Phalanx\System\HttpStream;

/**
 * Adapts a coroutine-readable {@see HttpStream} into the SSE event
 * stream Athena providers consume.
 *
 * The adapter owns the byte loop: it pulls bytes from the HttpStream
 * via `read()`, feeds them into a fresh {@see SseParser}, and yields
 * each parsed event as `{event: ?string, data: string}`. EOF on the
 * upstream HttpStream terminates the iteration.
 *
 * Lifecycle: the caller owns the HttpStream — it must close the stream
 * after the iterator finishes (typically via try/finally inside the
 * provider's __invoke). HttpSseSource does not close the stream itself
 * because providers may want to inspect status/headers post-iteration.
 */
final class HttpSseSource
{
    public function __construct(private readonly HttpStream $stream)
    {
    }

    /**
     * @return Generator<int, array{event: ?string, data: string}>
     */
    public function events(Suspendable $scope): Generator
    {
        $parser = new SseParser();
        while (!$this->stream->eof) {
            $scope->throwIfCancelled();
            $chunk = $this->stream->read($scope);
            if ($chunk === '') {
                break;
            }
            foreach ($parser->feed($chunk) as $event) {
                yield $event;
            }
        }
    }
}
