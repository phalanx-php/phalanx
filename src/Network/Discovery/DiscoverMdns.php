<?php

declare(strict_types=1);

namespace Phalanx\Network\Discovery;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Mark\Mark;
use Phalanx\Network\DiscoveryResult;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\TaskScope;
use Phalanx\System\UdpSocket;
use Phalanx\Task\Scopeable;
use Throwable;

/**
 * mDNS (Multicast DNS) implementation.
 *
 * Discovers services on the local network using UDP multicast (224.0.0.251:5353)
 * via the managed Runtime UdpSocket primitive.
 */
final class DiscoverMdns implements Scopeable, Recoverable
{
    private const string MULTICAST_ADDRESS = '224.0.0.251';
    private const int MULTICAST_PORT = 5353;

    public RecoveryPlan $recovery {
        get {
            return $this->recoveryPlan();
        }
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

            $socket->send($scope, MdnsPacket::ptrQuery($this->serviceType));

            $results = [];
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
                        foreach (MdnsPacket::ptrAnswers($response) as $answer) {
                            $results[] = new DiscoveryResult(
                                ip: $answer['data'],
                                protocol: 'mdns',
                                metadata: $answer,
                            );
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

    private function recoveryPlan(): RecoveryPlan
    {
        return RecoveryPlan::failFast(deadline: Mark::s($this->listenSeconds + 1.0));
    }
}
