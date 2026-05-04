<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\System\TlsOptions;
use PHPUnit\Framework\TestCase;

/**
 * The TlsOptions value object renders to the OpenSwoole Client::set()
 * shape. The defaults match OpenSwoole's documented secure-by-default
 * stance (verify_peer on, allow_self_signed off). Null fields fall through
 * to OpenSwoole's own defaults rather than emitting empty option keys.
 */
final class TlsOptionsTest extends TestCase
{
    public function testDefaultsRenderVerifyPeerOnAndAllowSelfSignedOff(): void
    {
        $options = new TlsOptions();

        self::assertSame(
            ['ssl_verify_peer' => true, 'ssl_allow_self_signed' => false],
            $options->toClientOptions(),
        );
    }

    public function testHostNameAndCaFileRenderWhenSet(): void
    {
        $options = new TlsOptions(
            hostName: 'api.example.com',
            caFile: '/etc/ssl/cert.pem',
        );

        $rendered = $options->toClientOptions();

        self::assertArrayHasKey('ssl_host_name', $rendered);
        self::assertSame('api.example.com', $rendered['ssl_host_name']);
        self::assertArrayHasKey('ssl_cafile', $rendered);
        self::assertSame('/etc/ssl/cert.pem', $rendered['ssl_cafile']);
    }

    public function testCertAndKeyAndPassphraseRenderWhenSet(): void
    {
        $options = new TlsOptions(
            certFile: '/etc/ssl/client.crt',
            keyFile: '/etc/ssl/client.key',
            passphrase: 'secret',
        );

        $rendered = $options->toClientOptions();

        self::assertSame('/etc/ssl/client.crt', $rendered['ssl_cert_file']);
        self::assertSame('/etc/ssl/client.key', $rendered['ssl_key_file']);
        self::assertSame('secret', $rendered['ssl_passphrase']);
    }

    public function testCiphersAndProtocolsRenderWhenSet(): void
    {
        $options = new TlsOptions(
            ciphers: 'ECDHE-RSA-AES256-GCM-SHA384',
            protocols: 'TLSv1.2 TLSv1.3',
        );

        $rendered = $options->toClientOptions();

        self::assertSame('ECDHE-RSA-AES256-GCM-SHA384', $rendered['ssl_ciphers']);
        self::assertSame('TLSv1.2 TLSv1.3', $rendered['ssl_protocols']);
    }

    public function testNullFieldsAreOmitted(): void
    {
        $rendered = (new TlsOptions())->toClientOptions();

        self::assertArrayNotHasKey('ssl_host_name', $rendered);
        self::assertArrayNotHasKey('ssl_cafile', $rendered);
        self::assertArrayNotHasKey('ssl_capath', $rendered);
        self::assertArrayNotHasKey('ssl_cert_file', $rendered);
        self::assertArrayNotHasKey('ssl_key_file', $rendered);
        self::assertArrayNotHasKey('ssl_passphrase', $rendered);
        self::assertArrayNotHasKey('ssl_ciphers', $rendered);
        self::assertArrayNotHasKey('ssl_protocols', $rendered);
    }
}
