<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\Scope\ExecutionScope;
use Phalanx\System\DnsLookupResult;
use Phalanx\System\DnsResolver;
use Phalanx\Testing\PhalanxTestCase;

final class DnsResolverTest extends PhalanxTestCase
{
    public function testResolvesLocalhost(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): DnsLookupResult {
            $resolver = new DnsResolver(defaultTimeout: 2.0);
            return $resolver->resolve($scope, 'localhost');
        });

        self::assertInstanceOf(DnsLookupResult::class, $result);
        self::assertTrue($result->resolved);
        self::assertSame('localhost', $result->hostname);
        self::assertNotNull($result->first());
        self::assertGreaterThanOrEqual(0.0, $result->durationMs);
    }

    public function testResolveAllReturnsAddressList(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): DnsLookupResult {
            $resolver = new DnsResolver();
            return $resolver->resolveAll($scope, 'localhost');
        });

        self::assertInstanceOf(DnsLookupResult::class, $result);
        self::assertTrue($result->resolved);
        foreach ($result->addresses as $addr) {
            self::assertIsString($addr);
        }
    }

    public function testFailedLookupReturnsEmptyAddresses(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): DnsLookupResult {
            $resolver = new DnsResolver(defaultTimeout: 0.5);
            return $resolver->resolve($scope, 'this-host-should-not-exist.invalid');
        });

        self::assertInstanceOf(DnsLookupResult::class, $result);
        self::assertFalse($result->resolved);
        self::assertNull($result->first());
        self::assertSame([], $result->addresses);
    }
}
