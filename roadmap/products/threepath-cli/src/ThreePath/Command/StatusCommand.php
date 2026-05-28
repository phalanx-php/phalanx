<?php

declare(strict_types=1);

namespace ThreePath\Command;

use Phalanx\Archon\CommandContext;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use ThreePath\StbCommand;
use ThreePath\StbConfig;
use ThreePath\Task\SendStbCommand;

final class StatusCommand implements Executable
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
            command: StbCommand::tunerStatus(),
        ));

        if (!$response->success) {
            echo "Failed: " . ($response->error ?? $response->status) . "\n";
            return 1;
        }

        $details = $response->data['details'] ?? $response->data;

        echo "Tuner Status for {$ip}:\n";
        echo "  Frequency:  " . ($details['frequency'] ?? '?') . " MHz\n";
        echo "  Mode:       " . ($details['mode'] ?? '?') . "\n";
        echo "  AGC:        " . ($details['agc'] ?? '?') . "%\n";
        echo "  SNR:        " . ($details['snr'] ?? '?') . "\n";
        echo "  Symbol:     " . ($details['symbol'] ?? '?') . " Ksps\n";
        echo "  Lock:       " . ($details['lock'] ?? '?') . "\n";

        return 0;
    }
}
