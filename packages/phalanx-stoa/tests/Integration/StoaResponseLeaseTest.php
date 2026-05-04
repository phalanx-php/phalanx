<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Response\ResponseLeaseDomain;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\LeaseExpectation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StoaResponseLeaseTest extends TestCase
{
    #[Test]
    public function acquireRecordsLeaseAndReleaseClearsIt(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());
        $context = new RuntimeContext($memory);

        $resource = StoaRequestResource::open(
            runtime: $context,
            request: new ServerRequest('GET', '/lease'),
            token: CancellationToken::none(),
            fd: 99,
        );

        $resource->activate();
        $resource->acquireDeliveryLease(99);

        self::assertSame(1, self::countLeases($memory, ResponseLeaseDomain::DOMAIN));

        $resource->releaseDeliveryLease('fulfilled');

        (new LeaseExpectation($memory))->releasedFor(ResponseLeaseDomain::DOMAIN);
    }

    #[Test]
    public function acquireIsIdempotentAndReleaseIsIdempotent(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());
        $context = new RuntimeContext($memory);

        $resource = StoaRequestResource::open(
            runtime: $context,
            request: new ServerRequest('GET', '/lease'),
            token: CancellationToken::none(),
            fd: 7,
        );
        $resource->activate();

        $resource->acquireDeliveryLease(7);
        $resource->acquireDeliveryLease(7);
        self::assertSame(1, self::countLeases($memory, ResponseLeaseDomain::DOMAIN));

        $resource->releaseDeliveryLease('fulfilled');
        $resource->releaseDeliveryLease('fulfilled');
        (new LeaseExpectation($memory))->releasedFor(ResponseLeaseDomain::DOMAIN);
    }

    #[Test]
    public function abandonReleasesLeaseEvenWithoutFulfilled(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());
        $context = new RuntimeContext($memory);

        $resource = StoaRequestResource::open(
            runtime: $context,
            request: new ServerRequest('GET', '/lease'),
            token: CancellationToken::none(),
            fd: 12,
        );
        $resource->activate();

        $resource->acquireDeliveryLease(12);
        $resource->releaseDeliveryLease('abandoned:test');

        (new LeaseExpectation($memory))->releasedFor(ResponseLeaseDomain::DOMAIN);
    }

    #[Test]
    public function dispatchWithoutFdDoesNotAcquireLease(): void
    {
        $app = Application::starting()->compile()->startup();

        try {
            $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([
                'GET /no-fd' => OkLeaseRoute::class,
            ]));

            $runner->dispatch(new ServerRequest('GET', '/no-fd'));

            (new LeaseExpectation($app->runtime()->memory))->releasedFor(ResponseLeaseDomain::DOMAIN);
        } finally {
            $app->shutdown();
        }
    }

    private static function countLeases(RuntimeMemory $memory, string $domain): int
    {
        $count = 0;
        foreach ($memory->tables->resourceLeases as $row) {
            if (is_array($row) && (string) $row['domain'] === $domain) {
                $count++;
            }
        }

        return $count;
    }
}

final class OkLeaseRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): string
    {
        return 'ok';
    }
}
