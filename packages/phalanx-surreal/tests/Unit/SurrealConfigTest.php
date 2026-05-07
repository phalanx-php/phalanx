<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Tests\Unit;

use Phalanx\Surreal\SurrealConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SurrealConfigTest extends TestCase
{
    #[Test]
    public function contextProvidesExplicitDatabaseAndTransportSettings(): void
    {
        $config = SurrealConfig::fromContext([
            'surreal_namespace' => 'athena',
            'surreal_database' => 'wisdom',
            'surreal_endpoint' => 'http://surreal.test:8000/',
            'surreal_username' => 'root',
            'surreal_password' => 'secret',
            'surreal_connect_timeout' => '1.5',
            'surreal_read_timeout' => '7.5',
            'surreal_max_response_bytes' => '4096',
        ]);

        self::assertSame('athena', $config->namespace);
        self::assertSame('wisdom', $config->database);
        self::assertSame('http://surreal.test:8000', $config->endpoint);
        self::assertSame('root', $config->username);
        self::assertSame('secret', $config->password);
        self::assertSame(1.5, $config->connectTimeout);
        self::assertSame(7.5, $config->readTimeout);
        self::assertSame(4096, $config->maxResponseBytes);
    }

    #[Test]
    public function contextAcceptsEnvironmentStyleKeys(): void
    {
        $config = SurrealConfig::fromContext([
            'SURREAL_NAMESPACE' => 'olympus',
            'SURREAL_DATABASE' => 'pantheon',
            'SURREAL_ENDPOINT' => 'http://surreal.test:8000/',
            'SURREAL_TOKEN' => 'jwt',
        ]);

        self::assertSame('olympus', $config->namespace);
        self::assertSame('pantheon', $config->database);
        self::assertSame('http://surreal.test:8000', $config->endpoint);
        self::assertSame('jwt', $config->token);
    }

    #[Test]
    public function withDatabaseDoesNotMutateBaseConfig(): void
    {
        $base = new SurrealConfig(namespace: 'athena', database: 'wisdom', token: 'token');
        $alternate = $base->withDatabase('argos', 'signals');

        self::assertSame('athena', $base->namespace);
        self::assertSame('wisdom', $base->database);
        self::assertSame('argos', $alternate->namespace);
        self::assertSame('signals', $alternate->database);
        self::assertSame('token', $alternate->token);
    }
}
