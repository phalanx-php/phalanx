<?php

declare(strict_types=1);

namespace Phalanx\Console\Examples\Commands;

use Clue\React\Docker\Client;
use Phalanx\Console\Arg;
use Phalanx\Console\CommandConfig;
use Phalanx\Console\CommandScope;
use Phalanx\Task\Executable;

final class InspectCommand implements Executable
{
    public CommandConfig $config {
        get => new CommandConfig(
            description: 'Inspect a container',
            arguments: [Arg::required('container', 'Container ID or name')],
        );
    }

    public function __invoke(CommandScope $scope): int
    {

        $client = $scope->service(Client::class);
        $container = $scope->args->required('container');

        $info = $scope->await($client->containerInspect($container));

        echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    }
}
