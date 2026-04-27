<?php

declare(strict_types=1);

namespace Phalanx\Argos\Task;

use Phalanx\ExecutionScope;
use Phalanx\Argos\NetworkConfig;
use Phalanx\Task\Executable;
use React\Datagram\Factory as DatagramFactory;

final readonly class WakeHost implements Executable
{
    public function __construct(
        private string $mac,
        private ?string $broadcast = null,
        private ?int $port = null,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $config = $scope->service(NetworkConfig::class);
        $factory = $scope->service(DatagramFactory::class);

        $broadcast = $this->broadcast ?? $config->broadcastAddress;
        $port = $this->port ?? $config->wolPort;

        $cleanMac = str_replace([':', '-', '.'], '', $this->mac);

        if (strlen($cleanMac) !== 12 || !ctype_xdigit($cleanMac)) {
            throw new \InvalidArgumentException("Invalid MAC address: {$this->mac}");
        }

        $payload = str_repeat("\xFF", 6) . str_repeat(pack('H12', $cleanMac), 16);

        $client = $scope->await($factory->createClient("$broadcast:$port"));
        $client->send($payload);
        $client->close();

        return null;
    }
}
