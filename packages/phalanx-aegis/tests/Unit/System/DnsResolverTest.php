<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\Scope\ExecutionScope;
use Phalanx\System\DnsLookupResult;
use Phalanx\System\DnsResolver;
use Phalanx\Tests\Support\CoroutineTestCase;

final class DnsResolverTest extends CoroutineTestCase
{
    public function testResolvesLocalhost(): void
    {
        $result = null;

        $this->runScoped(static function (ExecutionScope $scope) use (&$result): void {
            $resolver = new DnsResolver(defaultTimeout: 2.0);
            $result = $resolver->resolve($scope, 'localhost');
        });

        self::assertInstanceOf(DnsLookupResult::class, $result);
        self::assertTrue($result->resolved);
        self::assertSame('localhost', $result->hostname);
        self::assertNotNull($result->first());
        self::assertGreaterThanOrEqual(0.0, $result->durationMs);
    }

    public function testResolveAllReturnsAddressList(): void
    {
        $result = null;

        $this->runScoped(static function (ExecutionScope $scope) use (&$result): void {
            $resolver = new DnsResolver();
            $result = $resolver->resolveAll($scope, 'localhost');
        });

        self::assertInstanceOf(DnsLookupResult::class, $result);
        self::assertTrue($result->resolved);
        foreach ($result->addresses as $addr) {
            self::assertIsString($addr);
        }
    }

    public function testFailedLookupReturnsEmptyAddresses(): void
    {
        $result = null;

        $this->runScoped(static function (ExecutionScope $scope) use (&$result): void {
            $resolver = new DnsResolver(defaultTimeout: 0.5);
            $result = $resolver->resolve($scope, 'this-host-should-not-exist.invalid');
        });

        self::assertInstanceOf(DnsLookupResult::class, $result);
        self::assertFalse($result->resolved);
        self::assertNull($result->first());
        self::assertSame([], $result->addresses);
    }
}
