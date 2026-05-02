<?php

declare(strict_types=1);

use Acme\StoaDemo\Basic\Routes\Home;
use Acme\StoaDemo\Basic\Routes\ShowPost;
use Acme\StoaDemo\Basic\Routes\ShowUser;
use Phalanx\Stoa\RouteGroup;

return RouteGroup::of([
    'GET /' => Home::class,
    'GET /posts/{slug:slug}' => ShowPost::class,
    'GET /users/{id:int}' => ShowUser::class,
]);
