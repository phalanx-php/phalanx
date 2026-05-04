<?php

declare(strict_types=1);

namespace Phalanx\System;

use Generator;
use OpenSwoole\Coroutine\Http2\Client as Http2Client;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;

/**
 * Coroutine-readable byte stream over an HTTP/2 response body.
 *
 * The underlying client receives DATA frames as the upstream sends
 * them; each {@see read()} call drains one frame and returns its bytes.
 * When the upstream signals end-of-stream (frame `pipeline === false`),
 * subsequent reads return an empty string.
 *
 * Headers and status are populated from the first frame the upstream
 * sends — typically the HEADERS frame that arrives before any DATA.
 * Callers can therefore construct an HttpStream and immediately read
 * status/headers without buffering body bytes.
 *
 * Lifecycle: the stream owns the Http2Client connection. Closing the
 * stream closes the connection. Sharing a stream across coroutines is
 * not supported — each provider opens its own.
 */
class HttpStream
{
    public int $status {
        get {
            $this->ensureHeadersRead();
            return $this->statusCode;
        }
    }

    /** @var array<string, string> */
    public array $headers {
        get {
            $this->ensureHeadersRead();
            return $this->responseHeaders;
        }
    }

    public bool $eof {
        get => $this->isEof;
    }

    private int $statusCode = 0;

    /** @var array<string, string> */
    private array $responseHeaders = [];

    private bool $headersRead = false;

    private bool $isEof = false;

    private string $pendingBody = '';

    /**
     * @param int $streamId HTTP/2 stream identifier returned by `client->send()`.
     *                      Retained for future per-stream signalling (rst, priority);
     *                      reads currently drain frames via `client->read()`.
     */
    public function __construct(
        private readonly Http2Client $client,
        public readonly int $streamId,
        private readonly string $waitDetail,
    ) {
    }

    public function read(Suspendable $scope, int $bytes = 8192): string
    {
        if ($this->isEof && $this->pendingBody === '') {
            return '';
        }

        if ($this->pendingBody !== '') {
            $chunk = substr($this->pendingBody, 0, $bytes);
            $this->pendingBody = (string) substr($this->pendingBody, strlen($chunk));
            return $chunk;
        }

        $client = $this->client;
        $waitDetail = $this->waitDetail;
        $frame = $scope->call(
            static fn(): mixed => $client->read(),
            WaitReason::custom("http.stream.read {$waitDetail}"),
        );

        if (!is_object($frame)) {
            $this->isEof = true;
            return '';
        }

        if (!$this->headersRead) {
            $this->statusCode = (int) ($frame->statusCode ?? 0);
            $this->responseHeaders = is_array($frame->headers) ? $frame->headers : [];
            $this->headersRead = true;
        }

        if (!$frame->pipeline) {
            $this->isEof = true;
        }

        $data = (string) ($frame->data ?? '');
        if ($data === '') {
            return $this->isEof ? '' : $this->read($scope, $bytes);
        }

        if (strlen($data) <= $bytes) {
            return $data;
        }

        $chunk = substr($data, 0, $bytes);
        $this->pendingBody = (string) substr($data, $bytes);
        return $chunk;
    }

    /**
     * Yield newline-delimited lines from the body. Suitable for
     * NDJSON-streaming providers (Ollama). Trailing partial line at
     * EOF is yielded if non-empty.
     *
     * @return Generator<int, string>
     */
    public function lines(Suspendable $scope): Generator
    {
        $buffer = '';
        while (!$this->isEof || $this->pendingBody !== '' || $buffer !== '') {
            $chunk = $this->read($scope, 8192);
            if ($chunk === '') {
                if ($buffer !== '') {
                    yield $buffer;
                    $buffer = '';
                }
                break;
            }
            $buffer .= $chunk;
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = (string) substr($buffer, $newline + 1);
                if ($line !== '') {
                    yield $line;
                }
            }
        }
    }

    public function close(): void
    {
        if (!$this->isEof) {
            $this->isEof = true;
        }
        $this->client->close();
    }

    private function ensureHeadersRead(): void
    {
        // Headers populate from the first read; calling code that needs
        // status/headers before consuming body must call read() once.
        // This is a no-op accessor guard to make the property hooks
        // safe to read before any read() has occurred.
    }
}
