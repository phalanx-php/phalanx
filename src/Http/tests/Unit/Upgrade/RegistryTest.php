<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit\Upgrade;

use Swoole\Http\Response;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Http\Upgrade\Upgradeable;
use Phalanx\Http\Upgrade\Registry;
use Phalanx\Http\RequestResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class RegistryTest extends TestCase
{
    #[Test]
    public function resolvesRegisteredTokenCaseInsensitive(): void
    {
        $registry = new \Phalanx\Http\Upgrade\Registry();
        $upgradeable = self::stubUpgradeable();
        $registry->register('WebSocket', $upgradeable);

        self::assertTrue($registry->supports('websocket'));
        self::assertTrue($registry->supports('WEBSOCKET'));
        self::assertSame($upgradeable, $registry->resolve('websocket'));
    }

    #[Test]
    public function unregisteredTokenResolvesNull(): void
    {
        $registry = new \Phalanx\Http\Upgrade\Registry();
        self::assertNull($registry->resolve('h2c'));
        self::assertFalse($registry->supports('h2c'));
    }

    #[Test]
    public function tokensListsAllRegistered(): void
    {
        $registry = new \Phalanx\Http\Upgrade\Registry();
        $registry->register('websocket', self::stubUpgradeable());
        $registry->register('h2c', self::stubUpgradeable());

        $tokens = $registry->tokens();
        sort($tokens);
        self::assertSame(['h2c', 'websocket'], $tokens);
    }

    private static function stubUpgradeable(): \Phalanx\Http\Upgrade\Upgradeable
    {
        return new class () implements \Phalanx\Http\Upgrade\Upgradeable {
            public function upgrade(
                ServerRequestInterface $request,
                Response $target,
                \Phalanx\Http\RequestResource $requestResource,
            ): ManagedResourceHandle {
                throw new \RuntimeException('not invoked in this test');
            }
        };
    }
}
