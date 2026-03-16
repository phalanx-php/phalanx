<?php

declare(strict_types=1);

namespace Convoy\Parallel\Runtime;

final class ServiceProxy
{
    /**
     * @param resource $stdin
     * @param resource $stdout
     */
    public function __construct(private readonly string $serviceClass, private $stdin, private $stdout, private readonly WorkerScope $scope)
    {
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->scope->callService($this->serviceClass, $method, $args);
    }
}
