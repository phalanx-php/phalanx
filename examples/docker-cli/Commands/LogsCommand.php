<?php

declare(strict_types=1);

namespace Phalanx\Console\Examples\Commands;

use Clue\React\Docker\Client;
use Phalanx\Console\Arg;
use Phalanx\Console\CommandConfig;
use Phalanx\Console\CommandScope;
use Phalanx\Console\Opt;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

use function React\Async\await;

final class LogsCommand implements Scopeable
{
    public CommandConfig $config {
        get => new CommandConfig(
            description: 'Fetch container logs',
            arguments: [Arg::required('container', 'Container ID or name')],
            options: [Opt::value('tail', 'n', 'Number of lines from the end', default: '50')],
        );
    }

    public function __invoke(Scope $scope): int
    {
        assert($scope instanceof CommandScope);

        $client = $scope->service(Client::class);
        $container = $scope->args->required('container');
        $tail = $scope->options->get('tail', '50');

        $logs = await($client->containerLogs($container, false, true, true, 0, false, (int) $tail));

        echo $logs;
        return 0;
    }
}
