<?php

declare(strict_types=1);

namespace Phalanx\Eidolon\Signal;

final class TokenSignal implements Signal
{
    public private(set) ?string $token;
    public private(set) ?int $expiresIn;

    public SignalType $type {
        get => SignalType::Token;
    }

    public SignalPriority $priority {
        get => SignalPriority::Token;
    }

    public function __construct(?string $token, ?int $expiresIn = null)
    {
        $this->token     = $token;
        $this->expiresIn = $expiresIn;
    }

    public function toArray(): array
    {
        return [
            'type'       => SignalType::Token->value,
            'token'      => $this->token,
            'expires_in' => $this->expiresIn,
        ];
    }
}
