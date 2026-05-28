<?php

declare(strict_types=1);

namespace AgentBridge;

final readonly class BridgeConfig
{
    public function __construct(
        public string $dataDir,
        public int $port = 9078,
        public float $actionTimeoutSeconds = 30.0,
        public int $classifierBufferCount = 20,
        public float $classifierBufferSeconds = 2.0,
        public int $maxEventsPerSecThrottled = 5,
    ) {}
}
