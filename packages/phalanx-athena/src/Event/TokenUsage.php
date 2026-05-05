<?php

declare(strict_types=1);

namespace Phalanx\Athena\Event;

final class TokenUsage
{
    public int $total {
        get => $this->input + $this->output;
    }

    public function __construct(
        public private(set) int $input = 0,
        public private(set) int $output = 0,
    ) {
    }

    public static function zero(): self
    {
        return new self(0, 0);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            input: (int) ($data['input'] ?? 0),
            output: (int) ($data['output'] ?? 0),
        );
    }

    public function add(self $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->output + $other->output,
        );
    }

    /** @return array{input: int, output: int, total: int} */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'output' => $this->output,
            'total' => $this->total,
        ];
    }
}
