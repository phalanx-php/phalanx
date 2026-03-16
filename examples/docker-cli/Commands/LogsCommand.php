<?php

declare(strict_types=1);

namespace Convoy\Console\Examples\Commands;

use Clue\React\Docker\Client;
use Convoy\Console\CommandConfig;
use Convoy\Console\CommandScope;
use Convoy\Scope;
use Convoy\Task\Scopeable;

use function React\Async\await;

final class LogsCommand implements Scopeable
{
    public CommandConfig $config {
        get => (new CommandConfig())
            ->withDescription('Fetch container logs')
            ->withArgument('container', 'Container ID or name', required: true)
            ->withOption('tail', shorthand: 'n', description: 'Number of lines from the end', requiresValue: true, default: '50');
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
