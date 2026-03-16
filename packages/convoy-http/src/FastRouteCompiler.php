<?php

declare(strict_types=1);

namespace Convoy\Http;

use Convoy\Handler\Handler;
use FastRoute\ConfigureRoutes;
use FastRoute\Dispatcher;

use function FastRoute\simpleDispatcher;

final class FastRouteCompiler
{
    private Dispatcher $dispatcher;

    /** @param array<string, Handler> $handlerMap */
    public function __construct(private array $handlerMap)
    {
        $this->dispatcher = simpleDispatcher(function (ConfigureRoutes $r) use ($handlerMap): void {
            foreach ($handlerMap as $key => $handler) {
                if (!$handler->config instanceof RouteConfig) {
                    continue;
                }

                $path = $handler->config->path;
                if ($path === '') {
                    continue;
                }

                foreach ($handler->config->methods as $method) {
                    $r->addRoute($method, $path, $key);
                }
            }
        });
    }

    /**
     * @return array{handler: Handler, params: array<string, string>}
     */
    public function dispatch(string $method, string $path): array
    {
        $result = $this->dispatcher->dispatch($method, $path);

        return match ($result[0]) {
            Dispatcher::FOUND => [
                'handler' => $this->handlerMap[$result[1]],
                'params' => $result[2],
            ],
            Dispatcher::METHOD_NOT_ALLOWED => throw new MethodNotAllowedException(
                $method,
                $path,
                $result[1],
            ),
            default => throw new RouteNotFoundException($method, $path),
        };
    }
}
