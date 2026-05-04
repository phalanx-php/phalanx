<?php

declare(strict_types=1);

namespace Phalanx\Argos\Discovery;

use Clue\React\Mdns\Factory as MdnsFactory;
use Phalanx\Argos\DiscoveryResult;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Scopeable;
use React\Dns\Model\Message;
use React\Promise\Deferred;
use RuntimeException;
use Throwable;

use function React\Async\await;

final class DiscoverMdns implements Scopeable, HasTimeout
{
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
        if (!class_exists(MdnsFactory::class)) {
            throw new RuntimeException(
                'mDNS discovery requires clue/mdns-react. Install it: composer require clue/mdns-react',
            );
        }

        $factory = new MdnsFactory();
        $resolver = $factory->createResolver();

        /** @var list<DiscoveryResult> $results */
        $results = [];
        $deferred = new Deferred();
        $serviceType = $this->serviceType;

        $resolver->resolveAll($serviceType, Message::TYPE_PTR)
            ->then(
                static function (array $answers) use (&$results): void {
                    foreach ($answers as $answer) {
                        $results[] = new DiscoveryResult(
                            ip: is_string($answer) ? $answer : ($answer['ip'] ?? 'unknown'),
                            protocol: 'mdns',
                            metadata: is_array($answer) ? $answer : ['name' => $answer],
                        );
                    }
                },
                static function (Throwable $_e): void {
                    // mDNS queries can timeout without results -- not an error
                },
            )
            ->always(static function () use ($deferred): void {
                $deferred->resolve(true);
            });

        try {
            $scope->call(
                static fn(): mixed => await($deferred->promise()),
                WaitReason::custom("mdns.resolveAll {$serviceType}"),
            );
        } catch (Cancelled) {
            // scope timeout reached -- return whatever we collected
        }

        return $results;
    }
}
