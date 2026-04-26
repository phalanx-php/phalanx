<?php

declare(strict_types=1);

namespace Phalanx\Ui\Signal;

final class FlashSignal implements Signal
{
    /** @var non-empty-string[] */
    private const array VALID_LEVELS = ['success', 'error', 'warning', 'info'];

    public private(set) string $message;
    public private(set) string $level;

    public SignalType $type {
        get => SignalType::Flash;
    }

    public SignalPriority $priority {
        get => SignalPriority::Flash;
    }

    public function __construct(string $message, string $level = 'success')
    {
        if (!in_array($level, self::VALID_LEVELS, true)) {
            throw new \InvalidArgumentException(
                "Invalid flash level '$level'. Must be one of: " . implode(', ', self::VALID_LEVELS),
            );
        }

        $this->message = $message;
        $this->level   = $level;
    }

    public function toArray(): array
    {
        return [
            'type'    => SignalType::Flash->value,
            'message' => $this->message,
            'level'   => $this->level,
        ];
    }
}
