<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Slice;

use Phalanx\Theatron\Store\Slice;

final class DispatchFeedSlice implements Slice
{
    public string $key {
        get => 'showcase.feed';
    }

    /**
     * @param list<FeedMessage> $messages
     * @param array<string, FeedMessage> $streaming
     */
    public function __construct(
        private(set) array $messages = [],
        private(set) array $streaming = [],
    ) {
    }

    public function append(FeedMessage $message): self
    {
        return new self([...$this->messages, $message], $this->streaming);
    }

    public function appendToken(string $agentId, string $delta): self
    {
        $streaming = $this->streaming;

        if (isset($streaming[$agentId])) {
            $streaming[$agentId] = $streaming[$agentId]->appendText($delta);
        } else {
            $streaming[$agentId] = new FeedMessage($agentId, $delta, streaming: true);
        }

        return new self($this->messages, $streaming);
    }

    public function finalizeStream(string $agentId): self
    {
        $streaming = $this->streaming;

        if (!isset($streaming[$agentId])) {
            return $this;
        }

        $finalized = $streaming[$agentId]->finalize();
        unset($streaming[$agentId]);

        return new self([...$this->messages, $finalized], $streaming);
    }

    /** @return list<FeedMessage> */
    public function allMessages(): array
    {
        return [...$this->messages, ...array_values($this->streaming)];
    }
}
