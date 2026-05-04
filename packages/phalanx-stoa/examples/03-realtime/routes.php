<?php

declare(strict_types=1);

use Acme\StoaDemo\Realtime\Routes\Counter;
use Acme\StoaDemo\Realtime\Routes\Health;
use Acme\StoaDemo\Realtime\Routes\Proxy;
use Phalanx\Stoa\RouteGroup;

return RouteGroup::of([
    'GET /realtime/health' => Health::class,
    'GET /realtime/counter' => Counter::class,
    'GET /realtime/proxy' => Proxy::class,
]);
