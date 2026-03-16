<?php

declare(strict_types=1);

namespace Convoy\Console\Examples\Commands;

use Clue\React\Docker\Client;
use Convoy\Console\CommandConfig;
use Convoy\Console\CommandScope;
use Convoy\Scope;
use Convoy\Task\Scopeable;

use function React\Async\await;

final class InspectCommand implements Scopeable
{
    public CommandConfig $config {
        get => (new CommandConfig())
            ->withDescription('Inspect a container')
            ->withArgument('container', 'Container ID or name', required: true);
    }

    public function __invoke(Scope $scope): int
    {
        assert($scope instanceof CommandScope);

        $client = $scope->service(Client::class);
        $container = $scope->args->required('container');

        $info = await($client->containerInspect($container));

        echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    }
}
