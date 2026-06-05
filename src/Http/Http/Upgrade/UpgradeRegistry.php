<?php

declare(strict_types=1);

namespace Phalanx\Http\Http\Upgrade;

/**
 * Maps an HTTP `Upgrade:` token (lower-cased) to the package-provided
 * implementation that handles the protocol switch.
 *
 * Registration is build-time (WebSocket registers `'websocket'` during its
 * service-bundle boot). At request time the HttpRunner resolves the
 * token and either delegates or returns 426 Upgrade Required.
 */
final class UpgradeRegistry
{
    /** @var array<string, HttpUpgradeable> */
    private array $byToken = [];

    public function register(string $token, HttpUpgradeable $upgradeable): void
    {
        $this->byToken[strtolower(trim($token))] = $upgradeable;
    }

    public function resolve(string $token): ?HttpUpgradeable
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
