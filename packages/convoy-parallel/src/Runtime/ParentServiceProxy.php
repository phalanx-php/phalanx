<?php

declare(strict_types=1);

namespace Convoy\Parallel\Runtime;

use Convoy\Parallel\Protocol\ServiceCall;
use Convoy\Service\LazySingleton;
use Convoy\Service\ServiceGraph;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

final class ParentServiceProxy
{
    public function __construct(
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
    ) {
    }

    public function handle(ServiceCall $call): PromiseInterface
    {
        try {
            $service = $this->resolveService($call->serviceClass);

            if ($call->method === '__resolve__') {
                return resolve(true);
            }

            if (!method_exists($service, $call->method)) {
                return reject(new \RuntimeException(
                    "Method {$call->method} not found on {$call->serviceClass}"
                ));
            }

            $result = $service->{$call->method}(...$call->args);

            if ($result instanceof PromiseInterface) {
                return $result;
            }

            return resolve($result);
        } catch (\Throwable $e) {
            return reject($e);
        }
    }

    private function resolveService(string $type): object
    {
        $resolved = $this->graph->aliases[$type] ?? $type;

        if ($this->graph->hasConfig($resolved)) {
            return $this->graph->config($resolved);
        }

        $compiled = $this->graph->resolve($resolved);

        if ($compiled->singleton) {
            return $this->singletons->get($type, fn(string $t): object => $this->resolveService($t));
        }

        throw new \RuntimeException(
            "Cannot proxy non-singleton service from worker: $type. " .
            "Workers can only access singleton services."
        );
    }
}
