<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit\Http\Upgrade;

use Swoole\Http\Response;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Http\Http\Upgrade\HttpUpgradeable;
use Phalanx\Http\Http\Upgrade\UpgradeRegistry;
use Phalanx\Http\HttpRequestResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class UpgradeRegistryTest extends TestCase
{
    #[Test]
    public function resolvesRegisteredTokenCaseInsensitive(): void
    {
        $registry = new UpgradeRegistry();
        $upgradeable = self::stubUpgradeable();
        $registry->register('WebSocket', $upgradeable);

        self::assertTrue($registry->supports('websocket'));
        self::assertTrue($registry->supports('WEBSOCKET'));
        self::assertSame($upgradeable, $registry->resolve('websocket'));
    }

    #[Test]
    public function unregisteredTokenResolvesNull(): void
    {
        $registry = new UpgradeRegistry();
        self::assertNull($registry->resolve('h2c'));
        self::assertFalse($registry->supports('h2c'));
    }

    #[Test]
    public function tokensListsAllRegistered(): void
    {
        $registry = new UpgradeRegistry();
        $registry->register('websocket', self::stubUpgradeable());
        $registry->register('h2c', self::stubUpgradeable());

        $tokens = $registry->tokens();
        sort($tokens);
        self::assertSame(['h2c', 'websocket'], $tokens);
    }

    private static function stubUpgradeable(): HttpUpgradeable
    {
        return new class () implements HttpUpgradeable {
            public function upgrade(
                ServerRequestInterface $request,
                Response $target,
                HttpRequestResource $requestResource,
            ): ManagedResourceHandle {
                throw new \RuntimeException('not invoked in this test');
            }
        };
    }
}
