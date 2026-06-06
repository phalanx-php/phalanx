<?php

declare(strict_types=1);

namespace Phalanx\Worker\Runtime;

final class ServiceProxy
{
    public function __construct(
        private readonly WorkerScope $scope,
        private readonly string $serviceClass,
    ) {
    }

    /**
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->scope->callService($this->serviceClass, $method, $args);
    }
}
