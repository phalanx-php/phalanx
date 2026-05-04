<?php

declare(strict_types=1);

namespace Phalanx\System;

/**
 * Typed value object for the OpenSwoole SSL/TLS options accepted by
 * `OpenSwoole\Coroutine\Client::set()` when the SWOOLE_SSL flag is enabled.
 *
 * The whitelist below mirrors the option keys OpenSwoole 26.2 documents for
 * coroutine TLS clients. Using this object keeps SSL config out of the
 * stringly-typed `setOption()` surface and prevents key drift across
 * consumers.
 *
 * Reference: vendor/openswoole/ide-helper for the live ABI; OpenSwoole docs
 * for the tested option set.
 */
final readonly class TlsOptions
{
    public function __construct(
        public bool $verifyPeer = true,
        public bool $allowSelfSigned = false,
        public ?string $hostName = null,
        public ?string $caFile = null,
        public ?string $caPath = null,
        public ?string $certFile = null,
        public ?string $keyFile = null,
        public ?string $passphrase = null,
        public ?string $ciphers = null,
        public ?string $protocols = null,
    ) {
    }

    /**
     * Render to the associative array shape OpenSwoole's `Client::set()`
     * consumes. Null entries are dropped so OpenSwoole defaults stay in
     * effect for unset fields.
     *
     * @return array<string, string|int|bool>
     */
    public function toClientOptions(): array
    {
        $options = [
            'ssl_verify_peer' => $this->verifyPeer,
            'ssl_allow_self_signed' => $this->allowSelfSigned,
        ];

        if ($this->hostName !== null) {
            $options['ssl_host_name'] = $this->hostName;
        }
        if ($this->caFile !== null) {
            $options['ssl_cafile'] = $this->caFile;
        }
        if ($this->caPath !== null) {
            $options['ssl_capath'] = $this->caPath;
        }
        if ($this->certFile !== null) {
            $options['ssl_cert_file'] = $this->certFile;
        }
        if ($this->keyFile !== null) {
            $options['ssl_key_file'] = $this->keyFile;
        }
        if ($this->passphrase !== null) {
            $options['ssl_passphrase'] = $this->passphrase;
        }
        if ($this->ciphers !== null) {
            $options['ssl_ciphers'] = $this->ciphers;
        }
        if ($this->protocols !== null) {
            $options['ssl_protocols'] = $this->protocols;
        }

        return $options;
    }
}
