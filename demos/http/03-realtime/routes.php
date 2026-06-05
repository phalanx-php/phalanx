<?php

declare(strict_types=1);

use Acme\HttpDemo\Realtime\Routes\Counter;
use Acme\HttpDemo\Realtime\Routes\Health;
use Acme\HttpDemo\Realtime\Routes\Proxy;
use Phalanx\Http\RouteGroup;

return RouteGroup::of([
    'GET /realtime/health' => Health::class,
    'GET /realtime/counter' => Counter::class,
    'GET /realtime/proxy' => Proxy::class,
]);
