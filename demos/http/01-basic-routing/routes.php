<?php

declare(strict_types=1);

use Acme\HttpDemo\Basic\Routes\Home;
use Acme\HttpDemo\Basic\Routes\ShowPost;
use Acme\HttpDemo\Basic\Routes\ShowUser;
use Phalanx\Http\RouteGroup;

return RouteGroup::of([
    'GET /' => Home::class,
    'GET /posts/{slug:slug}' => ShowPost::class,
    'GET /users/{id:int}' => ShowUser::class,
]);
