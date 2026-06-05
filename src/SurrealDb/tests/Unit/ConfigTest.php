<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Tests\Unit;

use Phalanx\Boot\AppContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    #[Test]
    public function contextProvidesExplicitDatabaseAndTransportSettings(): void
    {
        $config = \Phalanx\SurrealDb\Config::fromContext(new AppContext([
            'SURREAL_NAMESPACE' => 'olympus',
            'SURREAL_DATABASE' => 'pantheon',
            'SURREAL_ENDPOINT' => 'http://surrealdb.test:8000/',
            'SURREAL_WS_ENDPOINT' => 'ws://surrealdb.test:8000/rpc/',
            'SURREAL_USERNAME' => 'root',
            'SURREAL_PASSWORD' => 'secret',
            'SURREAL_CONNECT_TIMEOUT' => '1.5',
            'SURREAL_READ_TIMEOUT' => '7.5',
            'SURREAL_MAX_RESPONSE_BYTES' => '4096',
        ]));

        self::assertSame('olympus', $config->namespace);
        self::assertSame('pantheon', $config->database);
        self::assertSame('http://surrealdb.test:8000', $config->endpoint);
        self::assertSame('ws://surrealdb.test:8000/rpc', $config->websocketEndpoint);
        self::assertSame('root', $config->username);
        self::assertSame('secret', $config->password);
        self::assertSame(1.5, $config->connectTimeout);
        self::assertSame(7.5, $config->readTimeout);
        self::assertSame(4096, $config->maxResponseBytes);
    }

    #[Test]
    public function contextIgnoresLegacyLowercaseKeys(): void
    {
        $config = \Phalanx\SurrealDb\Config::fromContext(new AppContext([
            'surrealdb_namespace' => 'olympus',
            'surrealdb_database' => 'pantheon',
            'surrealdb_endpoint' => 'http://surrealdb.test:8000/',
            'surrealdb_token' => 'jwt',
        ]));

        self::assertSame('phalanx', $config->namespace);
        self::assertSame('app', $config->database);
        self::assertSame('http://127.0.0.1:8000', $config->endpoint);
        self::assertSame('ws://127.0.0.1:8000/rpc', $config->websocketEndpoint);
        self::assertNull($config->token);
    }

    #[Test]
    public function websocketEndpointDerivesFromHttpsEndpoint(): void
    {
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon', endpoint: 'https://surrealdb.test');

        self::assertSame('wss://surrealdb.test/rpc', $config->websocketEndpoint);
    }

    #[Test]
    public function withDatabaseDoesNotMutateBaseConfig(): void
    {
        $base = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon', token: 'token');
        $alternate = $base->withDatabase('network', 'signals');

        self::assertSame('olympus', $base->namespace);
        self::assertSame('pantheon', $base->database);
        self::assertSame('network', $alternate->namespace);
        self::assertSame('signals', $alternate->database);
        self::assertSame($base->websocketEndpoint, $alternate->websocketEndpoint);
        self::assertSame('token', $alternate->token);
    }
}
