<?php

declare(strict_types=1);

namespace Convoy\Console\Examples\Commands;

use Clue\React\Docker\Client;
use Convoy\Console\CommandConfig;
use Convoy\Console\CommandScope;
use Convoy\Scope;
use Convoy\Task\Scopeable;

use function React\Async\await;

final class PsCommand implements Scopeable
{
    public CommandConfig $config {
        get => (new CommandConfig())
            ->withDescription('List containers')
            ->withOption('all', shorthand: 'a', description: 'Show all containers (default shows just running)');
    }

    public function __invoke(Scope $scope): int
    {
        assert($scope instanceof CommandScope);

        $client = $scope->service(Client::class);
        $all = $scope->options->flag('all');
        $containers = await($client->containerList($all));

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
