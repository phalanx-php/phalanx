#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phalanx Debug Dashboard
 *
 * A dump server + live metrics monitor. Any PHP app can POST dumps to it;
 * the browser dashboard streams them live via WebSocket.
 *
 * Usage:
 *   php examples/debug-dashboard/server.php
 *
 * Then open http://localhost:8080 in your browser.
 *
 * To send dumps from any PHP app, paste this helper:
 *
 *   function phalanx_dump(mixed $data, string $channel = 'app'): void {
 *       $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
 *       @file_get_contents('http://localhost:8080/dump', false, stream_context_create([
 *           'http' => [
 *               'method' => 'POST',
 *               'header' => "Content-Type: application/json\r\n",
 *               'content' => json_encode([
 *                   'channel' => $channel,
 *                   'data' => $data,
 *                   'file' => $trace['file'] ?? null,
 *                   'line' => $trace['line'] ?? null,
 *               ]),
 *               'timeout' => 1,
 *           ],
 *       ]));
 *   }
 *
 * Then call it anywhere:
 *   phalanx_dump(['user' => $user, 'query' => $sql], 'database');
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/DumpStore.php';
require __DIR__ . '/DashboardBundle.php';
require __DIR__ . '/Routes/DashboardPage.php';
require __DIR__ . '/Routes/DumpReceiver.php';
require __DIR__ . '/Routes/TestGenerator.php';
require __DIR__ . '/Ws/DumpStream.php';
require __DIR__ . '/Ws/MetricsStream.php';

use Phalanx\Application;
use Phalanx\Http\Route;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsRouteGroup;

$gateway = new WsGateway();

$app = Application::starting()
    ->providers(new DashboardBundle($gateway))
    ->compile();

$httpRoutes = RouteGroup::of([
    'GET /'               => Route::of(fn: new DashboardPage()),
    'POST /dump'          => Route::of(fn: new DumpReceiver()),
    'POST /test/generate' => Route::of(fn: new TestGenerator()),
]);

$wsRoutes = WsRouteGroup::of([
    '/dumps'   => DumpStream::route(),
    '/metrics' => MetricsStream::route(),
], gateway: $gateway);

Runner::from($app)
    ->withRoutes($httpRoutes)
    ->withWebsockets($wsRoutes)
    ->run('0.0.0.0:8080');
