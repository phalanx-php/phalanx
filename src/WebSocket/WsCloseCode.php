<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

enum WsCloseCode: int
{
    case Normal = 1000;
    case GoingAway = 1001;
    case ProtocolError = 1002;
    case UnsupportedData = 1003;
    case NoStatusReceived = 1005;
    case AbnormalClosure = 1006;
    case InvalidPayload = 1007;
    case PolicyViolation = 1008;
    case MessageTooBig = 1009;
    case MandatoryExtension = 1010;
    case InternalError = 1011;
    case ServiceRestart = 1012;
    case TryAgainLater = 1013;

    public function isValid(): bool
    {
        return $this->value >= 1000 && $this->value <= 4999;
    }

    public function isReserved(): bool
    {
        return match ($this) {
            self::NoStatusReceived, self::AbnormalClosure => true,
            default => false,
        };
    }
}
