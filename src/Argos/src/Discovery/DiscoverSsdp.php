<?php

declare(strict_types=1);

namespace Phalanx\Argos\Discovery;

use Phalanx\Argos\DiscoveryResult;
use Phalanx\Scope\TaskScope;
use Phalanx\System\UdpSocket;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Scopeable;

/**
 * SSDP (Simple Service Discovery Protocol) implementation.
 *
 * Discovers UPnP/SSDP devices on the local network using UDP multicast
 * (239.255.255.250:1900) via the managed Aegis UdpSocket primitive.
 */
final class DiscoverSsdp implements Scopeable, HasTimeout
{
    private const string MULTICAST_ADDRESS = '239.255.255.250';
    private const int MULTICAST_PORT = 1900;

    public float $timeout {
        get => $this->listenSeconds + 1.0;
    }

    public function __construct(
        private readonly string $searchTarget = 'ssdp:all',
        private readonly float $listenSeconds = 5.0,
    ) {
    }

    /** @return list<DiscoveryResult> */
    public function __invoke(TaskScope $scope): array
    {
        $socket = new UdpSocket();
        $socket->setBroadcast(true);

        try {
            $socket->connect($scope, self::MULTICAST_ADDRESS, self::MULTICAST_PORT);

            $packet = "M-SEARCH * HTTP/1.1\r\n" .
                      "HOST: " . self::MULTICAST_ADDRESS . ":" . self::MULTICAST_PORT . "\r\n" .
                      "MAN: \"ssdp:discover\"\r\n" .
                      "MX: " . (int) $this->listenSeconds . "\r\n" .
                      "ST: {$this->searchTarget}\r\n" .
                      "\r\n";

            $socket->send($scope, $packet);

            $results = [];
            $start = microtime(true);
            $deadline = $start + $this->listenSeconds;

            while (microtime(true) < $deadline) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }

                $response = $socket->recv($scope, $remaining);
                if ($response) {
                    $parsed = self::parseResponse($response);
                    if ($parsed !== null) {
                        $results[] = new DiscoveryResult(
                            ip: (string) (parse_url($parsed['LOCATION'] ?? '', PHP_URL_HOST) ?? 'unknown'),
                            protocol: 'ssdp',
                            metadata: $parsed,
                        );
                    }
                }
            }

            return $results;
        } finally {
            $socket->close();
        }
    }

    /** @return array<string, string>|null */
    private static function parseResponse(string $response): ?array
    {
        $lines = explode("\r\n", trim($response));
        if (count($lines) < 1 || !str_starts_with($lines[0], 'HTTP/1.1 200 OK')) {
            return null;
        }

        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtoupper(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $headers;
    }
}
