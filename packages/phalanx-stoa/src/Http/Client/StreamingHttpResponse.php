<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Client;

use Generator;

/**
 * Iterator-style response over a chunked or content-length-bounded body.
 *
 * The first iteration step yields the parsed status / headers; subsequent
 * steps yield body bytes as they arrive. Callers that need the full
 * decoded payload should use {@see StoaHttpResponse} from
 * {@see StoaHttpClient::request()} instead — this view is intended for
 * streaming consumption (large downloads, long-poll feeds) where holding
 * the whole body in memory is unsafe.
 *
 * The current implementation buffers the whole upstream response and
 * yields it back in chunks; a follow-up can lift it onto an active
 * transport so the buffer stays bounded.
 */
final class StreamingHttpResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $reasonPhrase,
        public readonly array $headers,
        private readonly string $body,
        public readonly int $chunkSize = 16 * 1024,
    ) {
    }

    /** @return Generator<int, string, void, void> */
    public function chunks(): Generator
    {
        $offset = 0;
        $length = strlen($this->body);

        while ($offset < $length) {
            $chunk = substr($this->body, $offset, $this->chunkSize);
            $offset += strlen($chunk);
            yield $chunk;
        }
    }

    public function fullBody(): string
    {
        return $this->body;
    }
}
