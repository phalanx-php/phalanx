<?php

declare(strict_types=1);

namespace Phalanx\HttpClient\Tests\Unit;

use Phalanx\Config\Env;
use Phalanx\HttpClient\Config;
use Phalanx\System\TlsOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class HttpClientConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreSensibleForProduction(): void
    {
        $config = new \Phalanx\HttpClient\Config();

        self::assertSame(5.0, $config->connectTimeout);
        self::assertSame(30.0, $config->readTimeout);
        self::assertSame(16 * 1024 * 1024, $config->maxResponseBytes);
        self::assertSame('Phalanx-HttpClient/0.6', $config->userAgent);
        self::assertNull($config->tlsOptions);
    }

    #[Test]
    public function tlsOptionsArePropagated(): void
    {
        $tls = new TlsOptions(verifyPeer: true, hostName: 'example.com', caFile: '/etc/ca.pem');
        $config = new \Phalanx\HttpClient\Config(tlsOptions: $tls);

        self::assertSame($tls, $config->tlsOptions);
        self::assertSame('example.com', $config->tlsOptions->hostName);
        self::assertSame('/etc/ca.pem', $config->tlsOptions->caFile);
        self::assertTrue($config->tlsOptions->verifyPeer);
    }

    #[Test]
    public function envKeysUseHttpClientVocabulary(): void
    {
        $constructor = new ReflectionClass(\Phalanx\HttpClient\Config::class)->getConstructor();
        self::assertNotNull($constructor);

        $keys = [];
        foreach ($constructor->getParameters() as $parameter) {
            $env = Env::fromParameter($parameter);
            if ($env !== null) {
                $keys[$parameter->getName()] = $env->key;
            }
        }

        self::assertSame([
            'connectTimeout' => 'HTTP_CLIENT_CONNECT_TIMEOUT',
            'readTimeout' => 'HTTP_CLIENT_READ_TIMEOUT',
            'maxResponseBytes' => 'HTTP_CLIENT_MAX_RESPONSE_BYTES',
            'userAgent' => 'HTTP_CLIENT_USER_AGENT',
        ], $keys);
    }
}
