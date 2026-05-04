<?php

declare(strict_types=1);

namespace Phalanx\System\Http1;

use RuntimeException;

/**
 * Streaming decoder for HTTP/1.1 Transfer-Encoding: chunked bodies.
 *
 * Pure byte-level state machine: callers feed inbound TCP bytes via
 * {@see feed()} and pull decoded body bytes via {@see drain()}. The
 * decoder never blocks and never owns a socket — composition with the
 * coroutine-aware reader lives one level up.
 *
 * Wire grammar (RFC 7230 §4.1):
 *
 *     chunk          = chunk-size [ chunk-ext ] CRLF chunk-data CRLF
 *     last-chunk     = "0" [ chunk-ext ] CRLF
 *     chunked-body   = *chunk last-chunk trailer-part CRLF
 *
 * Trailer headers are accepted and discarded; we read until the empty
 * line that terminates the trailer section. Once the terminator is
 * consumed, {@see eof} flips true and subsequent {@see drain()} calls
 * return empty strings.
 */
final class ChunkedDecoder
{
    private const STATE_SIZE = 0;
    private const STATE_BODY = 1;
    private const STATE_BODY_CRLF = 2;
    private const STATE_TRAILER = 3;
    private const STATE_EOF = 4;

    public bool $eof {
        get => $this->isEof;
    }

    private string $buffer = '';

    private string $decoded = '';

    private int $state = self::STATE_SIZE;

    private int $chunkRemaining = 0;

    private bool $isEof = false;

    public function feed(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $this->buffer .= $bytes;
        $this->advance();
    }

    /**
     * Pull up to $maxBytes of decoded body. Returns less than $maxBytes
     * (including '') when the decoder needs more wire bytes — caller
     * should {@see feed()} more from the socket and retry.
     */
    public function drain(int $maxBytes): string
    {
        if ($this->decoded === '') {
            return '';
        }
        if (strlen($this->decoded) <= $maxBytes) {
            $out = $this->decoded;
            $this->decoded = '';
            return $out;
        }
        $out = substr($this->decoded, 0, $maxBytes);
        $this->decoded = (string) substr($this->decoded, $maxBytes);
        return $out;
    }

    /**
     * Whether more wire bytes are needed before another decoded byte is
     * available. Caller uses this to decide whether to loop on the
     * socket or yield back to the consumer.
     */
    public function needsMore(): bool
    {
        return !$this->isEof && $this->decoded === '';
    }

    private function advance(): void
    {
        while (true) {
            switch ($this->state) {
                case self::STATE_SIZE:
                    if (!$this->advanceSize()) {
                        return;
                    }
                    break;

                case self::STATE_BODY:
                    if (!$this->advanceBody()) {
                        return;
                    }
                    break;

                case self::STATE_BODY_CRLF:
                    if (!$this->advanceBodyCrlf()) {
                        return;
                    }
                    break;

                case self::STATE_TRAILER:
                    if (!$this->advanceTrailer()) {
                        return;
                    }
                    break;

                case self::STATE_EOF:
                    return;
            }
        }
    }

    private function advanceSize(): bool
    {
        $crlf = strpos($this->buffer, "\r\n");
        if ($crlf === false) {
            return false;
        }
        $line = substr($this->buffer, 0, $crlf);
        $this->buffer = (string) substr($this->buffer, $crlf + 2);

        // Strip optional chunk-ext after ';'
        $semi = strpos($line, ';');
        $hex = $semi === false ? $line : substr($line, 0, $semi);
        $hex = trim($hex);
        if ($hex === '' || !ctype_xdigit($hex)) {
            throw new RuntimeException("ChunkedDecoder: invalid chunk size line '{$line}'");
        }
        $size = hexdec($hex);
        if (!is_int($size) || $size < 0) {
            throw new RuntimeException("ChunkedDecoder: invalid chunk size '{$hex}'");
        }

        if ($size === 0) {
            $this->state = self::STATE_TRAILER;
            return true;
        }

        $this->chunkRemaining = $size;
        $this->state = self::STATE_BODY;
        return true;
    }

    private function advanceBody(): bool
    {
        if ($this->buffer === '') {
            return false;
        }
        $take = min($this->chunkRemaining, strlen($this->buffer));
        $this->decoded .= substr($this->buffer, 0, $take);
        $this->buffer = (string) substr($this->buffer, $take);
        $this->chunkRemaining -= $take;
        if ($this->chunkRemaining === 0) {
            $this->state = self::STATE_BODY_CRLF;
            return true;
        }
        return false;
    }

    private function advanceBodyCrlf(): bool
    {
        if (strlen($this->buffer) < 2) {
            return false;
        }
        if (substr($this->buffer, 0, 2) !== "\r\n") {
            throw new RuntimeException('ChunkedDecoder: missing CRLF after chunk body');
        }
        $this->buffer = (string) substr($this->buffer, 2);
        $this->state = self::STATE_SIZE;
        return true;
    }

    private function advanceTrailer(): bool
    {
        // Trailer lines are header-shaped; we discard them and wait for
        // the empty line terminator (\r\n on its own). Many servers send
        // no trailers at all — the chunked body just ends with \r\n
        // after the "0" size line.
        while (true) {
            $crlf = strpos($this->buffer, "\r\n");
            if ($crlf === false) {
                return false;
            }
            $this->buffer = (string) substr($this->buffer, $crlf + 2);
            if ($crlf === 0) {
                $this->state = self::STATE_EOF;
                $this->isEof = true;
                return true;
            }
            // Discard trailer header line; continue.
        }
    }
}
