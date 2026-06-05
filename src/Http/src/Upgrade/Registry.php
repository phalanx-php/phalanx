<?php

declare(strict_types=1);

namespace Phalanx\Http\Upgrade;

/**
 * Maps an HTTP `Upgrade:` token (lower-cased) to the package-provided
 * implementation that handles the protocol switch.
 *
 * Registration is build-time (WebSocket registers `'websocket'` during its
 * service-bundle boot). At request time the Runner resolves the
 * token and either delegates or returns 426 Upgrade Required.
 */
final class Registry
{
    /** @var array<string, \Phalanx\Http\Upgrade\Upgradeable> */
    private array $byToken = [];

    public function register(string $token, \Phalanx\Http\Upgrade\Upgradeable $upgradeable): void
    {
        $this->byToken[strtolower(trim($token))] = $upgradeable;
    }

    public function resolve(string $token): ?\Phalanx\Http\Upgrade\Upgradeable
    {
        return $this->byToken[strtolower(trim($token))] ?? null;
    }

    public function supports(string $token): bool
    {
        return isset($this->byToken[strtolower(trim($token))]);
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return array_keys($this->byToken);
    }
}
