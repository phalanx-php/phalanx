<?php

declare(strict_types=1);

namespace ThreePath\Command;

use Phalanx\Archon\CommandContext;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use ThreePath\StbCommand;
use ThreePath\StbConfig;
use ThreePath\Task\SendStbCommand;

final class ChannelSwitchCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        /** @var CommandContext $scope */
        /** @var StbConfig $config */
        $config = $scope->service(StbConfig::class);
        $ip = $scope->args->get('ip') ?? $config->defaultDeviceIp;
        $deviceId = $scope->options->get('device-id') ?? $config->defaultDeviceId;
        $serviceId = (int) ($scope->args->get('service-id') ?? $config->defaultServiceId);

        $response = $scope->execute(new SendStbCommand(
            ip: $ip,
            deviceId: $deviceId,
            command: StbCommand::forceChannelSwitch($serviceId),
        ));

        if (!$response->success) {
            echo "Failed: " . ($response->error ?? 'unknown error') . "\n";
            return 1;
        }

        echo "Switched {$ip} to service {$serviceId}\n";
        return 0;
    }
}
