<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Athena\Router\InvocationRouter;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Harness\Template\AppStore;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider;
use Phalanx\Panoply\Transport;
use Phalanx\Scope\TaskScope;

final class LlmRequestRecordingRouter implements InvocationRouter
{
    public function __construct(
        private(set) InvocationRouter $inner,
    ) {
    }

    public function route(TaskScope $scope, Agent $agent, Invocation $invocation): Provider
    {
        $provider = $this->inner->route($scope, $agent, $invocation);
        $store = self::store($scope);

        if ($store === null) {
            return $provider;
        }

        return self::decorateProvider($provider, $store, $invocation);
    }

    private static function decorateProvider(Provider $provider, AppStore $store, Invocation $invocation): Provider
    {
        $reflection = new \ReflectionClass($provider);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $provider;
        }

        $args = [];
        $decorated = false;

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (!$reflection->hasProperty($name)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $args[] = $parameter->getDefaultValue();
                    continue;
                }

                return $provider;
            }

            $property = $reflection->getProperty($name);
            if (!$property->isPublic() || !$property->isInitialized($provider)) {
                return $provider;
            }

            $value = $property->getValue($provider);

            if ($name === 'transport') {
                if (!$value instanceof Transport) {
                    return $provider;
                }

                if (!$value instanceof LlmRequestRecordingTransport) {
                    $value = LlmRequestRecordingTransport::wrap($value, $store, $invocation->id);
                }

                $decorated = true;
            }

            $args[] = $value;
        }

        if (!$decorated) {
            return $provider;
        }

        return $reflection->newInstanceArgs($args);
    }

    private static function store(TaskScope $scope): ?AppStore
    {
        try {
            return $scope->service(AppStore::class);
        } catch (ServiceNotFoundException) {
            return null;
        }
    }
}
