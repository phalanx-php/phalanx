<?php

declare(strict_types=1);

namespace Phalanx\Eidolon\Signal;

final class EventSignal implements Signal
{
    public private(set) string $name;

    /** @var array<string, mixed> */
    public private(set) array $payload;

    public SignalType $type {
        get => SignalType::Event;
    }

    public SignalPriority $priority {
        get => SignalPriority::Event;
    }

    /** @param array<string, mixed> $payload */
    public function __construct(string $name, array $payload = [])
    {
        $this->name    = $name;
        $this->payload = $payload;
    }

    public function toArray(): array
    {
        return [
            'type'    => SignalType::Event->value,
            'name'    => $this->name,
            'payload' => $this->payload,
        ];
    }
}
