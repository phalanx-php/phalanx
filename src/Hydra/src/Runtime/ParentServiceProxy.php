<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Runtime;

use Phalanx\Hydra\Protocol\ServiceCall;
use Phalanx\Scope\Scope;
use RuntimeException;

class ParentServiceProxy
{
    public function __construct(
        private readonly Scope $scope,
    ) {
    }

    public function handle(ServiceCall $call): mixed
    {
        $service = $this->scope->service($this->serviceClass($call));

        if ($call->method === '__resolve__') {
            return true;
        }

        if (!method_exists($service, $call->method)) {
            throw new RuntimeException("Method {$call->method} not found on {$call->serviceClass}");
        }

        return $service->{$call->method}(...$call->args);
    }

    /** @return class-string */
    private function serviceClass(ServiceCall $call): string
    {
        if (!class_exists($call->serviceClass) && !interface_exists($call->serviceClass)) {
            throw new RuntimeException("Service class {$call->serviceClass} not found");
        }

        /** @var class-string $class */
        $class = $call->serviceClass;
        return $class;
    }
}
