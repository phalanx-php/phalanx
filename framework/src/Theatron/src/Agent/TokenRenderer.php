<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Agent;

final class TokenRenderer
{
    private string $buffer = '';
    /** @var 'message'|'thinking' */
    private string $currentChannel = 'message';

    /**
     * Accepts a text fragment and a channel. Buffers until a complete line
     * boundary and returns any complete lines (including the trailing newline).
     * Returns empty string when no complete line is available yet.
     *
     * A channel switch flushes the current buffer before processing new text,
     * so any partial line from the previous channel is not lost.
     *
     * @param 'message'|'thinking' $channel
     */
    public function append(string $text, string $channel = 'message'): string
    {
        if ($channel !== $this->currentChannel) {
            $flushed = $this->buffer;
            $this->buffer = '';
            $this->currentChannel = $channel;

            return $flushed . $this->extractCompleteLines($text);
        }

        return $this->extractCompleteLines($text);
    }

    /**
     * Flush any remaining buffered text. Call this on TokenStop to emit the
     * trailing partial line that never ended with a newline.
     */
    public function flush(): string
    {
        $remaining = $this->buffer;
        $this->buffer = '';

        return $remaining;
    }

    /**
     * @return 'message'|'thinking'
     */
    public function channel(): string
    {
        return $this->currentChannel;
    }

    /**
     * Appends new text to the internal buffer, then extracts and returns every
     * complete line (text ending with \n). The trailing incomplete fragment
     * stays in the buffer for the next call.
     */
    private function extractCompleteLines(string $text): string
    {
        $this->buffer .= $text;

        $lastNewline = strrpos($this->buffer, "\n");
        if ($lastNewline === false) {
            return '';
        }

        $complete = substr($this->buffer, 0, $lastNewline + 1);
        $this->buffer = substr($this->buffer, $lastNewline + 1);

        return $complete;
    }
}
