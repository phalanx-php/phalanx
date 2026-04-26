<?php

declare(strict_types=1);

namespace Phalanx\Ui\Signal;

enum SignalPriority: int
{
    case Invalidate = 0;
    case Token      = 1;
    case Flash      = 2;
    case Event      = 3;
    case Redirect   = 4;

    public static function forType(SignalType $type): self
    {
        return match ($type) {
            SignalType::Invalidate => self::Invalidate,
            SignalType::Token      => self::Token,
            SignalType::Flash      => self::Flash,
            SignalType::Event      => self::Event,
            SignalType::Redirect   => self::Redirect,
        };
    }
}
