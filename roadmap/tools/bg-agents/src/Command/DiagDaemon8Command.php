<?php

declare(strict_types=1);

namespace BgAgents\Command;

use BgAgents\Config\BgAgentsConfig;
use BgAgents\Daemon8\ObservationClient;
use BgAgents\Daemon8\ObservationQuery;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class DiagDaemon8Command implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        $config = $scope->service(BgAgentsConfig::class);
        $client = $scope->service(ObservationClient::class);

        fwrite(STDOUT, "daemon8 url: {$config->daemon8Url}\n");

        try {
            $healthy = $scope->await($client->health());
            $checkpoint = $scope->await($client->checkpoint());
        } catch (\Throwable $e) {
            fwrite(STDERR, "diag failed: {$e->getMessage()}\n");
            return 1;
        }

        if (!$healthy) {
            fwrite(STDERR, "health: not ok\n");
            return 1;
        }

        fwrite(STDOUT, "health:     ok\n");
        fwrite(STDOUT, "checkpoint: {$checkpoint}\n");

        $result = $scope->await($client->observe(new ObservationQuery(limit: 1)));
        $count = count($result['observations']);
        fwrite(STDOUT, "sample observation count: {$count}\n");

        return 0;
    }
}
