<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

final class TokenRenderer
{
    private string $buffer = '';
    /** @var 'message'|'thinking' */
    private string $currentChannel = 'message';

    /** @param 'message'|'thinking' $channel */
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
