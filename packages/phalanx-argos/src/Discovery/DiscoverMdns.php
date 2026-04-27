<?php

declare(strict_types=1);

namespace Phalanx\Argos\Discovery;

use Phalanx\ExecutionScope;
use Phalanx\Argos\DiscoveryResult;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;
use React\Promise\Deferred;

final class DiscoverMdns implements Executable, HasTimeout
{
    public float $timeout { get => $this->listenSeconds + 1.0; }

    public function __construct(
        private readonly string $serviceType = '_services._dns-sd._udp.local',
        private readonly float $listenSeconds = 5.0,
    ) {}

    /** @return list<DiscoveryResult> */
    public function __invoke(ExecutionScope $scope): array
    {
        if (!class_exists(\Clue\React\Mdns\Factory::class)) {
            throw new \RuntimeException(
                'mDNS discovery requires clue/mdns-react. Install it: composer require clue/mdns-react',
            );
        }

        $factory = new \Clue\React\Mdns\Factory();
        $resolver = $factory->createResolver();

        $results = [];
        $deferred = new Deferred();

        $resolver->resolveAll($this->serviceType, \React\Dns\Model\Message::TYPE_PTR)
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
                static function (\Throwable $e): void {
                    // mDNS queries can timeout without results -- not an error
                },
            )
            ->always(static function () use ($deferred): void {
                $deferred->resolve(true);
            });

        try {
            $scope->await($deferred->promise());
        } catch (\Phalanx\Exception\CancelledException) {
            // scope timeout reached -- return whatever we collected
        }

        return $results;
    }
}
