<?php

declare(strict_types=1);

namespace Phalanx\Argos\Discovery;

use Phalanx\Argos\DiscoveryResult;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\TaskScope;
use Phalanx\System\UdpSocket;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Scopeable;
use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\Dns\Query\Query;
use Throwable;

/**
 * mDNS (Multicast DNS) implementation.
 *
 * Discovers services on the local network using UDP multicast (224.0.0.251:5353)
 * via the managed Aegis UdpSocket primitive and React\Dns message components.
 */
final class DiscoverMdns implements Scopeable, HasTimeout
{
    private const string MULTICAST_ADDRESS = '224.0.0.251';
    private const int MULTICAST_PORT = 5353;

    public float $timeout {
        get => $this->listenSeconds + 1.0;
    }

    public function __construct(
        private readonly string $serviceType = '_services._dns-sd._udp.local',
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

            $message = new Message();
            $message->id = 0;
            $message->qr = false;
            $message->questions[] = new Query($this->serviceType, Message::TYPE_PTR, Message::CLASS_IN);

            $dumper = new BinaryDumper();
            $packet = $dumper->toBinary($message);

            $socket->send($scope, $packet);

            $results = [];
            $parser = new Parser();
            $start = microtime(true);
            $deadline = $start + $this->listenSeconds;

            while (microtime(true) < $deadline) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }

                try {
                    $response = $socket->recv($scope, $remaining);
                    if ($response) {
                        $responseMessage = $parser->parseMessage($response);
                        foreach ($responseMessage->answers as $answer) {
                            if ($answer->type === Message::TYPE_PTR) {
                                $results[] = new DiscoveryResult(
                                    ip: is_string($answer->data) ? $answer->data : 'unknown',
                                    protocol: 'mdns',
                                    metadata: [
                                        'name' => $answer->name,
                                        'data' => $answer->data,
                                    ],
                                );
                            }
                        }
                    }
                } catch (Cancelled) {
                    break;
                } catch (Throwable) {
                    /**
                     * Discovery tolerates noisy local-network traffic and
                     * keeps collecting usable answers until the deadline.
                     */
                }
            }

            return $results;
        } finally {
            $socket->close();
        }
    }
}
