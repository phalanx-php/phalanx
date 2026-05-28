<?php

declare(strict_types=1);

namespace App\Routes;

use Phalanx\Stoa\Route;
use Phalanx\Stoa\RouteGroup;

final class DashboardRoutes extends RouteGroup
{
    protected function routes(): array
    {
        return [
            Route::get('/admin/agent-oversight', [DashboardHandler::class, 'globalFeed']),
            Route::get('/admin/agent-oversight/{sessionId}', [SessionHandler::class, 'detail']),
        ];
    }
}
