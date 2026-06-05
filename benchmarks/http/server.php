#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload_runtime.php';

use GuzzleHttp\Psr7\Response;
use Phalanx\Http\RequestContext;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Http;
use Phalanx\Task\Scopeable;

final class JsonHandler implements Scopeable
{
    /** @return array{message: string} */
    public function __invoke(RequestContext $ctx): array
    {
        return ['message' => 'Hello, World!'];
    }
}

final class PlaintextHandler implements Scopeable
{
    public function __invoke(RequestContext $ctx): Response
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'Hello, World!');
    }
}

return static fn(array $context): \Closure => static function () use ($context): int {
    $listen = $context['argv'][1] ?? '0.0.0.0:8080';

    return \Phalanx\Http\Server::starting($context)
        ->routes(RouteGroup::of([
            'GET /json'      => JsonHandler::class,
            'GET /plaintext' => PlaintextHandler::class,
        ]))
        ->listen($listen)
        ->run();
};
