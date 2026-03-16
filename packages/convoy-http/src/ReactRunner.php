<?php

declare(strict_types=1);

namespace Convoy\Http;

use Convoy\AppHost;
use Convoy\Task\Executable;
use Symfony\Component\Runtime\RunnerInterface;

final class ReactRunner implements RunnerInterface
{
    private ?Executable $handler = null;

    public function __construct(
        private readonly AppHost $app,
        private readonly string $host,
        private readonly int $port,
        private readonly float $requestTimeout = 30.0,
    ) {
    }

    public function withHandler(Executable $handler): self
    {
        $clone = clone $this;
        $clone->handler = $handler;
        return $clone;
    }

    public function run(): int
    {
        if ($this->handler === null) {
            throw new \LogicException('No request handler configured. Call withHandler() before run().');
        }

        $handler = $this->handler;
        $runner = Runner::from($this->app, $this->requestTimeout);

        if ($handler instanceof RouteGroup) {
            $runner->withRoutes($handler);
        } elseif ($handler instanceof \Convoy\Handler\HandlerGroup) {
            $runner->withRoutes(RouteGroup::fromHandlerGroup($handler));
        } else {
            $runner->withRoutes(RouteGroup::fromHandlerGroup(
                \Convoy\Handler\HandlerGroup::of(['default' => new \Convoy\Handler\Handler($handler, new \Convoy\Handler\HandlerConfig())]),
            ));
        }

        return $runner->run("{$this->host}:{$this->port}");
    }
}
