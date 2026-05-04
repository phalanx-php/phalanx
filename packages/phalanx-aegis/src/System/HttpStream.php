<?php

declare(strict_types=1);

namespace Phalanx\System;

use Generator;
use Phalanx\Scope\Suspendable;
use Phalanx\System\Http1\ChunkedDecoder;
use RuntimeException;

/**
 * Coroutine-readable byte stream over an HTTP/1.1 response body.
 *
 * Backed by a {@see TcpClient} the caller already opened and wrote a
 * request to. The first {@see read()} drains bytes until the response
 * status line and headers terminate with `\r\n\r\n`, then enters body
 * mode based on the response framing:
 *
 * - `Transfer-Encoding: chunked`  → per-chunk decoder (live streaming)
 * - `Content-Length: N`           → bounded raw read
 * - neither, with `Connection: close` → unbounded read until socket close
 *
 * Each {@see read()} call returns whatever decoded body bytes are
 * currently available, up to the requested cap. EOF is reported via
 * {@see eof}; subsequent reads return empty strings.
 *
 * Lifecycle: the stream owns the TcpClient. {@see close()} closes the
 * underlying socket. Sharing a stream across coroutines is unsupported.
 *
 * HTTP/2 was the original substrate but OpenSwoole 26's
 * `Coroutine\Http2\Client` does not deliver per-frame body data for
 * streaming responses (it buffers until END_STREAM), making real-time
 * SSE unworkable. Every LLM provider Athena targets accepts HTTP/1.1
 * with chunked SSE, so this is the canonical path.
 */
class HttpStream
{
    private const RECV_TIMEOUT = 60.0;

    private const MODE_HEADERS = 0;
    private const MODE_CHUNKED = 1;
    private const MODE_LENGTH = 2;
    private const MODE_CLOSE = 3;
    private const MODE_EOF = 4;

    public int $status {
        get => $this->statusCode;
    }

    /** @var array<string, string> */
    public array $headers {
        get => $this->responseHeaders;
    }

    public bool $eof {
        get => $this->isEof;
    }

    private int $statusCode = 0;

    /** @var array<string, string> */
    private array $responseHeaders = [];

    private bool $isEof = false;

    private int $mode = self::MODE_HEADERS;

    private string $buffer = '';

    private string $decoded = '';

    private ?ChunkedDecoder $chunked = null;

    private int $contentLengthRemaining = 0;

    public function __construct(
        private readonly TcpClient $client,
        private readonly string $waitDetail,
    ) {
    }

    public function read(Suspendable $scope, int $bytes = 8192): string
    {
        if ($this->isEof && $this->decoded === '') {
            return '';
        }

        if ($this->mode === self::MODE_HEADERS) {
            $this->readHeaders($scope);
        }

        while ($this->decoded === '' && !$this->isEof) {
            if (!$this->advanceBody($scope)) {
                break;
            }
        }

        return $this->drain($bytes);
    }

    /**
     * Yield newline-delimited lines from the body. Suitable for NDJSON
     * providers (Ollama). Trailing partial line at EOF is yielded if
     * non-empty.
     *
     * @return Generator<int, string>
     */
    public function lines(Suspendable $scope): Generator
    {
        $line = '';
        while (true) {
            $chunk = $this->read($scope);
            if ($chunk === '') {
                if ($line !== '') {
                    yield $line;
                }
                return;
            }
            $line .= $chunk;
            while (($nl = strpos($line, "\n")) !== false) {
                $out = substr($line, 0, $nl);
                $line = (string) substr($line, $nl + 1);
                if ($out !== '') {
                    yield $out;
                }
            }
        }
    }

    public function close(): void
    {
        $this->isEof = true;
        $this->client->close();
    }

    private function drain(int $bytes): string
    {
        if ($this->decoded === '') {
            return '';
        }
        if (strlen($this->decoded) <= $bytes) {
            $out = $this->decoded;
            $this->decoded = '';
            return $out;
        }
        $out = substr($this->decoded, 0, $bytes);
        $this->decoded = (string) substr($this->decoded, $bytes);
        return $out;
    }

    private function readHeaders(Suspendable $scope): void
    {
        while (($end = strpos($this->buffer, "\r\n\r\n")) === false) {
            if (!$this->fillBuffer($scope)) {
                throw new RuntimeException(
                    "HttpStream: connection closed before HTTP headers received ({$this->waitDetail})",
                );
            }
        }

        $head = substr($this->buffer, 0, $end);
        $this->buffer = (string) substr($this->buffer, $end + 4);

        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines) ?? '';
        if (!preg_match('#^HTTP/1\.\d\s+(\d+)#', $statusLine, $m)) {
            throw new RuntimeException("HttpStream: malformed status line '{$statusLine}'");
        }
        $this->statusCode = (int) $m[1];

        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $this->responseHeaders[$name] = $value;
        }

        $this->mode = $this->resolveBodyMode();

        // Pre-feed any body bytes that arrived in the same buffer as headers.
        if ($this->buffer !== '') {
            $this->ingestBody($this->buffer);
            $this->buffer = '';
        }
    }

    private function resolveBodyMode(): int
    {
        $te = strtolower($this->responseHeaders['transfer-encoding'] ?? '');
        if ($te !== '' && str_contains($te, 'chunked')) {
            $this->chunked = new ChunkedDecoder();
            return self::MODE_CHUNKED;
        }

        $cl = $this->responseHeaders['content-length'] ?? null;
        if ($cl !== null && ctype_digit($cl)) {
            $this->contentLengthRemaining = (int) $cl;
            if ($this->contentLengthRemaining === 0) {
                $this->isEof = true;
                return self::MODE_EOF;
            }
            return self::MODE_LENGTH;
        }

        return self::MODE_CLOSE;
    }

    private function advanceBody(Suspendable $scope): bool
    {
        if ($this->mode === self::MODE_EOF) {
            return false;
        }
        if (!$this->fillBuffer($scope)) {
            // Socket closed.
            $this->ingestBody('');
            $this->isEof = true;
            $this->mode = self::MODE_EOF;
            return false;
        }
        $bytes = $this->buffer;
        $this->buffer = '';
        $this->ingestBody($bytes);
        return true;
    }

    private function ingestBody(string $bytes): void
    {
        switch ($this->mode) {
            case self::MODE_CHUNKED:
                $this->chunked?->feed($bytes);
                if ($this->chunked !== null) {
                    while (true) {
                        $piece = $this->chunked->drain(8192);
                        if ($piece === '') {
                            break;
                        }
                        $this->decoded .= $piece;
                    }
                    if ($this->chunked->eof) {
                        $this->isEof = true;
                        $this->mode = self::MODE_EOF;
                    }
                }
                break;

            case self::MODE_LENGTH:
                if ($bytes === '') {
                    return;
                }
                $take = min($this->contentLengthRemaining, strlen($bytes));
                $this->decoded .= substr($bytes, 0, $take);
                $this->contentLengthRemaining -= $take;
                if ($this->contentLengthRemaining === 0) {
                    $this->isEof = true;
                    $this->mode = self::MODE_EOF;
                }
                break;

            case self::MODE_CLOSE:
                $this->decoded .= $bytes;
                break;
        }
    }

    private function fillBuffer(Suspendable $scope): bool
    {
        $chunk = $this->client->recv($scope, self::RECV_TIMEOUT);
        if ($chunk === null || $chunk === '') {
            return false;
        }
        $this->buffer .= $chunk;
        return true;
    }
}
