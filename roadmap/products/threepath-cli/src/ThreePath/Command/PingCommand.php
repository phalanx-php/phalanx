<?php

declare(strict_types=1);

namespace ThreePath\Command;

use Phalanx\Archon\CommandContext;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use ThreePath\StbConfig;
use ThreePath\Task\PingStb;

final class PingCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        /** @var CommandContext $scope */
        /** @var StbConfig $config */
        $config = $scope->service(StbConfig::class);
        $ip = $scope->args->get('ip') ?? $config->defaultDeviceIp;
        $response = $scope->execute(new PingStb($ip));

        if (!$response->success) {
            echo "No response from {$ip}" . ($response->timedOut ? ' (timeout)' : '') . "\n";
            return 1;
        }

        echo "STB found at {$ip}\n";
        echo "  Chip ID:  {$response->chipId}\n";
        echo "  Firmware: " . ($response->get('apk_version') ?? 'unknown') . "\n";
        echo "  IP:       " . ($response->get('ipAssignment') ?? 'unknown') . "\n";
        echo "  Date:     " . ($response->get('date') ?? 'unknown') . "\n";

        return 0;
    }
}
