<?php

declare(strict_types=1);

namespace Phalanx\Iris\Tests\Unit;

use Phalanx\Iris\HttpClientConfig;
use Phalanx\System\TlsOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpClientConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreSensibleForProduction(): void
    {
        $config = new HttpClientConfig();

        self::assertSame(5.0, $config->connectTimeout);
        self::assertSame(30.0, $config->readTimeout);
        self::assertSame(16 * 1024 * 1024, $config->maxResponseBytes);
        self::assertSame('Phalanx-Iris/0.6', $config->userAgent);
        self::assertNull($config->tlsOptions);
    }

    #[Test]
    public function tlsOptionsArePropagated(): void
    {
        $tls = new TlsOptions(verifyPeer: true, hostName: 'example.com', caFile: '/etc/ca.pem');
        $config = new HttpClientConfig(tlsOptions: $tls);

        self::assertSame($tls, $config->tlsOptions);
        self::assertSame('example.com', $config->tlsOptions->hostName);
        self::assertSame('/etc/ca.pem', $config->tlsOptions->caFile);
        self::assertTrue($config->tlsOptions->verifyPeer);
    }
}
