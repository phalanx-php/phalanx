<?php

declare(strict_types=1);

namespace Phalanx\Eidolon\Signal;

final class RedirectSignal implements Signal
{
    private(set) string $url;
    private(set) bool $replace;

    public SignalType $type {
        get => SignalType::Redirect;
    }

    public SignalPriority $priority {
        get => SignalPriority::Redirect;
    }

    public function __construct(string $url, bool $replace = false)
    {
        $this->url     = $url;
        $this->replace = $replace;
    }

    public function toArray(): array
    {
        return [
            'type'    => SignalType::Redirect->value,
            'to'      => $this->url,
            'replace' => $this->replace,
        ];
    }
}
