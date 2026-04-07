<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Runtime;

final class ServiceProxy
{
    public function __construct(
        private readonly string $serviceClass,
        private readonly WorkerScope $scope,
    ) {}

    /**
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->scope->callService($this->serviceClass, $method, $args);
    }
}
