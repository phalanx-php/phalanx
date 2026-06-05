<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Integration;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Http\RequestContext;
use Phalanx\Http\Response\ResponseLeaseDomain;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\RequestResource;
use Phalanx\Http\Runner;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\LeaseExpectation;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class HttpResponseLeaseTest extends PhalanxTestCase
{
    #[Test]
    public function acquireRecordsLeaseAndReleaseClearsIt(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());

        try {
            $context = new RuntimeContext($memory);
            $resource = \Phalanx\Http\RequestResource::open(
                runtime: $context,
                request: new ServerRequest('GET', '/lease'),
                token: CancellationToken::none(),
                fd: 99,
            );

            $resource->activate();
            $resource->acquireDeliveryLease(99);

            $expect = new LeaseExpectation($memory);
            $expect->heldFor(ResponseLeaseDomain::DOMAIN, 1);

            $resource->releaseDeliveryLease('fulfilled');

            $expect->releasedFor(ResponseLeaseDomain::DOMAIN);
        } finally {
            $memory->shutdown();
        }
    }

    #[Test]
    public function acquireIsIdempotentAndReleaseIsIdempotent(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());

        try {
            $context = new RuntimeContext($memory);
            $resource = \Phalanx\Http\RequestResource::open(
                runtime: $context,
                request: new ServerRequest('GET', '/lease'),
                token: CancellationToken::none(),
                fd: 7,
            );
            $resource->activate();

            $resource->acquireDeliveryLease(7);
            $resource->acquireDeliveryLease(7);
            $expect = new LeaseExpectation($memory);
            $expect->heldFor(ResponseLeaseDomain::DOMAIN, 1);

            $resource->releaseDeliveryLease('fulfilled');
            $resource->releaseDeliveryLease('fulfilled');
            $expect->releasedFor(ResponseLeaseDomain::DOMAIN);
        } finally {
            $memory->shutdown();
        }
    }

    #[Test]
    public function abandonReleasesLeaseEvenWithoutFulfilled(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());

        try {
            $context = new RuntimeContext($memory);
            $resource = \Phalanx\Http\RequestResource::open(
                runtime: $context,
                request: new ServerRequest('GET', '/lease'),
                token: CancellationToken::none(),
                fd: 12,
            );
            $resource->activate();

            $resource->acquireDeliveryLease(12);
            $resource->releaseDeliveryLease('abandoned:test');

            new LeaseExpectation($memory)->releasedFor(ResponseLeaseDomain::DOMAIN);
        } finally {
            $memory->shutdown();
        }
    }

    #[Test]
    public function dispatchWithoutFdDoesNotAcquireLease(): void
    {
        $app = $this->startedApplication();

        $this->scope->run(static function () use ($app): void {
            $runner = \Phalanx\Http\Runner::from($app)->withRoutes(RouteGroup::of([
                'GET /no-fd' => OkLeaseRoute::class,
            ]));

            $runner->dispatch(new ServerRequest('GET', '/no-fd'));

            new LeaseExpectation($app->runtime()->memory)->releasedFor(ResponseLeaseDomain::DOMAIN);
        });
    }

}

final class OkLeaseRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): string
    {
        return 'ok';
    }
}
