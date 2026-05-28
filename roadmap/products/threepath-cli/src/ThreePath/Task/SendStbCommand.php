<?php

declare(strict_types=1);

namespace ThreePath\Task;

use Phalanx\Scope;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Scopeable;
use ThreePath\StbCommand;
use ThreePath\StbResponse;
use ThreePath\StbTransport;

final class SendStbCommand implements Scopeable, HasTimeout
{
    public float $timeout { get => 5.0; }

    public function __construct(
        private readonly string $ip,
        private readonly string $deviceId,
        private readonly StbCommand $command,
    ) {}

    public function __invoke(Scope $scope): StbResponse
    {
        /** @var StbTransport $transport */
        $transport = $scope->service(StbTransport::class);

        return $transport->send($this->ip, $this->deviceId, $this->command);
    }
}
