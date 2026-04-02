<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;
use React\Dns\Resolver\ResolverInterface;

final class ResolveHostname implements Executable, HasTimeout
{
    public float $timeout { get => $this->timeoutSeconds; }

    public function __construct(
        private readonly string $hostname,
        private readonly float $timeoutSeconds = 5.0,
    ) {}

    /** @return list<string> */
    public function __invoke(ExecutionScope $scope): array
    {
        $resolver = $scope->service(ResolverInterface::class);

        $ips = $scope->await($resolver->resolveAll($this->hostname, \React\Dns\Model\Message::TYPE_A));

        return array_values(array_filter($ips, 'is_string'));
    }
}
