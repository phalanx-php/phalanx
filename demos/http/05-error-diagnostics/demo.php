<?php

declare(strict_types=1);

namespace Phalanx\Demos\Http\Diagnostics;

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Http\RequestContext;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Server;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;

final class FailingDemoHandler implements Scopeable
{
    public function __invoke(RequestContext $ctx): mixed
    {
        return $ctx->execute(Task::named('business_logic.process', static function (ExecutionScope $scope) {
            return $scope->execute(Task::named('gateway.external_api', static function (ExecutionScope $scope) {
                $scope->go(static fn(ExecutionScope $s) => $s->delay(Mark::s(10)), 'stats.collector');

                $scope->delay(Mark::ms(100));
                throw new \RuntimeException("External API Timeout: Service 'auth-provider' unavailable.");
            }));
        }));
    }
}

/**
 * Phalanx Http HTML Error Demo
 */
return DemoReport::demo(
    'Http Web Error Diagnostics',
    static function (DemoReport $report, AppContext $context): void {
        $report->note('This demo simulates a web request that triggers an unhandled exception.');

        $routes = RouteGroup::of([
            'GET /fail' => FailingDemoHandler::class,
        ]);

        $app = Server::starting($context->values)
            ->routes($routes)
            ->ignition()
            ->build();

        $request = new ServerRequest('GET', '/fail', ['Accept' => 'text/html']);
        $response = null;

        \Swoole\Coroutine\run(static function () use ($app, $request, &$response): void {
            $response = $app->dispatch($request);
        });

        $app->shutdown();

        if ($response === null) {
            $report->record('Dispatch failed to produce a response', false);
            return;
        }

        $body = (string) $response->getBody();
        $isHtml = str_contains($response->getHeaderLine('Content-Type'), 'text/html');
        $hasBrand = str_contains($body, 'Failing Logic') && str_contains($body, 'Concurrency Snapshot');

        $report->record('Response status is 500', $response->getStatusCode() === 500);
        $report->record('Response is HTML', $isHtml);
        $report->record('HTML contains branded error components', $hasBrand);

        if (!$isHtml || !$hasBrand) {
            $report->note('Raw Body Snippet: ' . substr($body, 0, 200));
        } else {
            $report->note('Successfully rendered high-fidelity HTML error page.');
        }
    },
);
