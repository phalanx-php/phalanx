<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Slice;

final class FeedMessage
{
    public function __construct(
        private(set) string $agentId,
        private(set) string $text,
        private(set) bool $streaming = false,
    ) {
    }

    public function appendText(string $delta): self
    {
        return new self($this->agentId, $this->text . $delta, $this->streaming);
    }

    public function finalize(): self
    {
        return new self($this->agentId, $this->text, streaming: false);
    }
}
