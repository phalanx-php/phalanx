<?php

declare(strict_types=1);

namespace ThreePath\Task;

use Phalanx\Scope;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Scopeable;
use ThreePath\StbResponse;
use ThreePath\StbTransport;

final class PingStb implements Scopeable, HasTimeout
{
    public float $timeout { get => 5.0; }

    public function __construct(
        private readonly string $ip,
    ) {}

    public function __invoke(Scope $scope): StbResponse
    {
        /** @var StbTransport $transport */
        $transport = $scope->service(StbTransport::class);

        return $transport->discover($this->ip);
    }
}
