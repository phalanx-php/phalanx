<?php

declare(strict_types=1);

namespace ThreePath\Command;

use Phalanx\Archon\CommandContext;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use ThreePath\StbCommand;
use ThreePath\StbConfig;
use ThreePath\Task\SendStbCommand;

final class ChannelDownCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        /** @var CommandContext $scope */
        /** @var StbConfig $config */
        $config = $scope->service(StbConfig::class);
        $ip = $scope->args->get('ip') ?? $config->defaultDeviceIp;
        $deviceId = $scope->options->get('device-id') ?? $config->defaultDeviceId;

        $response = $scope->execute(new SendStbCommand(
            ip: $ip,
            deviceId: $deviceId,
            command: StbCommand::channelDown(),
        ));

        echo $response->success ? "Channel down on {$ip}\n" : "Failed\n";
        return $response->success ? 0 : 1;
    }
}
