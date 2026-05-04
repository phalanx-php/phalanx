<?php

declare(strict_types=1);

namespace Phalanx\Argos\Task;

use InvalidArgumentException;
use Phalanx\Argos\NetworkConfig;
use Phalanx\Scope\TaskScope;
use Phalanx\System\UdpSocket;
use Phalanx\Task\Scopeable;

final readonly class WakeHost implements Scopeable
{
    public function __construct(
        private string $mac,
        private ?string $broadcast = null,
        private ?int $port = null,
    ) {
    }

    public function __invoke(TaskScope $scope): mixed
    {
        $config = $scope->service(NetworkConfig::class);

        $broadcast = $this->broadcast ?? $config->broadcastAddress;
        $port = $this->port ?? $config->wolPort;

        $cleanMac = str_replace([':', '-', '.'], '', $this->mac);

        if (strlen($cleanMac) !== 12 || !ctype_xdigit($cleanMac)) {
            throw new InvalidArgumentException("Invalid MAC address: {$this->mac}");
        }

        $payload = str_repeat("\xFF", 6) . str_repeat(pack('H12', $cleanMac), 16);

        $client = new UdpSocket();
        $client->setBroadcast(true);
        $client->connect($scope, $broadcast, $port, 1.0);
        $client->send($scope, $payload, 1.0);
        $client->close();

        return null;
    }
}
