<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Http\Cases;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Benchmarks\Http\AbstractHttpBenchmarkCase;
use Phalanx\Benchmarks\Http\HttpBenchmarkCase;
use Phalanx\Benchmarks\Kit\BenchmarkApp;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Task\Scopeable;

final class StoaDispatchDtoUnusedCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('stoa_dispatch_dto_unused', 5_000, 100);
    }

    public function run(BenchmarkApp $app): void
    {
        $payload = [
            'title' => 'Benchmark Task',
            'description' => str_repeat('A very long description ', 100),
            'meta' => array_fill(0, 50, ['key' => 'value', 'data' => str_repeat('x', 20)]),
        ];

        $response = $app->stoaRunner('dto-unused', RouteGroup::of([
            'POST /dto-unused' => BenchmarkDtoUnusedRoute::class,
        ]))->dispatch(new ServerRequest(
            'POST',
            '/dto-unused',
            ['Content-Type' => 'application/json'],
            json_encode($payload)
        ));

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('DTO unused benchmark route returned an unexpected status.');
        }
    }
}

final class StoaDispatchDtoUsedCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('stoa_dispatch_dto_used', 5_000, 100);
    }

    public function run(BenchmarkApp $app): void
    {
        $payload = [
            'title' => 'Benchmark Task',
            'description' => str_repeat('A very long description ', 100),
            'meta' => array_fill(0, 50, ['key' => 'value', 'data' => str_repeat('x', 20)]),
        ];

        $response = $app->stoaRunner('dto-used', RouteGroup::of([
            'POST /dto-used' => BenchmarkDtoUsedRoute::class,
        ]))->dispatch(new ServerRequest(
            'POST',
            '/dto-used',
            ['Content-Type' => 'application/json'],
            json_encode($payload)
        ));

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('DTO used benchmark route returned an unexpected status.');
        }
    }
}

final class BenchmarkDtoUnusedRoute implements Scopeable
{
    public function __invoke(RequestScope $scope, BenchmarkInput $input): Response
    {
        // $input is NEVER used. JSON should never be parsed.
        return new Response(200, [], 'ignored');
    }
}

final class BenchmarkDtoUsedRoute implements Scopeable
{
    public function __invoke(RequestScope $scope, BenchmarkInput $input): Response
    {
        // $input IS used. JSON will be parsed now.
        return new Response(200, [], $input->title);
    }
}

final readonly class BenchmarkInput
{
    public function __construct(
        public string $title,
        public ?string $description = null,
    ) {}
}

/** @return list<HttpBenchmarkCase> */
function lazyHttpCases(): array
{
    return [
        new StoaDispatchDtoUnusedCase(),
        new StoaDispatchDtoUsedCase(),
    ];
}
