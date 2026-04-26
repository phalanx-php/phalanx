<?php

declare(strict_types=1);

namespace Phalanx\Ui\Signal;

final class InvalidateSignal implements Signal
{
    /** @var list<string> */
    public private(set) array $queryKeys;

    public SignalType $type {
        get => SignalType::Invalidate;
    }

    public SignalPriority $priority {
        get => SignalPriority::Invalidate;
    }

    public function __construct(string ...$queryKeys)
    {
        $this->queryKeys = array_values($queryKeys);
    }

    public function toArray(): array
    {
        return [
            'type' => SignalType::Invalidate->value,
            'keys' => $this->queryKeys,
        ];
    }
}
