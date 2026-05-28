<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

use Phalanx\Theatron\Store\Slice;

class InputSlice implements Slice
{
    public string $key {
        get => 'repl.input';
    }

    /** @var list<string> $queue */
    public function __construct(
        private(set) string $text = '',
        private(set) array $queue = [],
    ) {
    }

    public function append(string $char): self
    {
        $clone = clone $this;
        $clone->text .= $char;

        return $clone;
    }

    public function backspace(): self
    {
        if ($this->text === '') {
            return $this;
        }

        $clone = clone $this;
        $clone->text = mb_substr($this->text, 0, -1);

        return $clone;
    }

    public function clear(): self
    {
        if ($this->text === '') {
            return $this;
        }

        $clone = clone $this;
        $clone->text = '';

        return $clone;
    }

    public function enqueue(string $message): self
    {
        $clone = clone $this;
        $clone->queue = [...$this->queue, $message];

        return $clone;
    }

    public function dequeue(): self
    {
        if ($this->queue === []) {
            return $this;
        }

        $clone = clone $this;
        $clone->queue = array_slice($this->queue, 1);

        return $clone;
    }

    public function peek(): ?string
    {
        return $this->queue[0] ?? null;
    }
}
