<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use FastRoute\ConfigureRoutes;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\Result\Matched;
use FastRoute\Dispatcher\Result\MethodNotAllowed;
use FastRoute\FastRoute;
use Phalanx\Handler\Handler;
use RuntimeException;

final class FastRouteCompiler
{
    private Dispatcher $dispatcher;

    /** @param array<string, Handler> $handlerMap */
    public function __construct(private array $handlerMap)
    {
        $this->dispatcher = FastRoute::recommendedSettings(
            static function (ConfigureRoutes $r) use ($handlerMap): void {
                foreach ($handlerMap as $key => $handler) {
                    if (!$handler->config instanceof RouteConfig) {
                        continue;
                    }

                    $path = $handler->config->fastRoutePath;
                    if ($path === '') {
                        continue;
                    }

                    $r->addRoute($handler->config->methods, $path, $key);
                }
            },
            'stoa-routes',
        )
            ->disableCache()
            ->dispatcher();
    }

    /**
     * @return array{handler: Handler, params: array<string, string>}
     */
    public function dispatch(string $method, string $path): array
    {
        $result = $this->dispatcher->dispatch($method, $path);

        if ($result instanceof Matched) {
            if (!is_string($result->handler) || !isset($this->handlerMap[$result->handler])) {
                throw new RuntimeException('FastRoute returned an unknown Stoa handler key.');
            }

            return [
                'handler' => $this->handlerMap[$result->handler],
                'params' => $result->variables,
            ];
        }

        if ($result instanceof MethodNotAllowed) {
            throw new MethodNotAllowedException($method, $path, $result->allowedMethods);
        }

        throw new RouteNotFoundException($method, $path);
    }
}
