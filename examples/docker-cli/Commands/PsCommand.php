<?php

declare(strict_types=1);

namespace Phalanx\Console\Examples\Commands;

use Clue\React\Docker\Client;
use Phalanx\Console\CommandConfig;
use Phalanx\Console\CommandScope;
use Phalanx\Console\Opt;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class PsCommand implements Executable
{
    public CommandConfig $config {
        get => new CommandConfig(
            description: 'List containers',
            options: [Opt::flag('all', 'a', 'Show all containers (default shows just running)')],
        );
    }

    public function __invoke(ExecutionScope $scope): int
    {
        assert($scope instanceof CommandScope);

        $client = $scope->service(Client::class);
        $all = $scope->options->flag('all');
        $containers = $scope->await($client->containerList($all));

        if ($containers === []) {
            echo "No containers" . ($all ? '' : ' running') . ".\n";
            return 0;
        }

        self::printContainers($containers);

        return 0;
    }

    private static function printContainers(array $containers): void
    {
        $idW = 12;
        $imgW = 30;
        $statusW = 20;

        printf("%-{$idW}s  %-{$imgW}s  %-{$statusW}s  %s\n", 'CONTAINER ID', 'IMAGE', 'STATUS', 'NAMES');

        foreach ($containers as $c) {
            $id = substr($c['Id'], 0, 12);
            $image = strlen($c['Image']) > $imgW ? substr($c['Image'], 0, $imgW - 3) . '...' : $c['Image'];
            $status = $c['Status'] ?? 'unknown';
            $names = implode(', ', array_map(fn($n) => ltrim($n, '/'), $c['Names'] ?? []));

            printf("%-{$idW}s  %-{$imgW}s  %-{$statusW}s  %s\n", $id, $image, $status, $names);
        }
    }
}
