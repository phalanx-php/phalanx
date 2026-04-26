<?php

declare(strict_types=1);

namespace Phalanx\Console\Examples\Commands;

use Clue\React\Docker\Client;
use Phalanx\Console\Arg;
use Phalanx\Console\CommandConfig;
use Phalanx\Console\CommandScope;
use Phalanx\Console\Opt;
use Phalanx\Task\Executable;

final class PullCommand implements Executable
{
    public CommandConfig $config {
        get => new CommandConfig(
            description: 'Pull an image',
            arguments: [Arg::required('image', 'Image name to pull')],
            options: [Opt::value('tag', 't', 'Image tag', default: 'latest')],
        );
    }

    public function __invoke(CommandScope $scope): int
    {

        $client = $scope->service(Client::class);
        $image = $scope->args->required('image');
        $tag = $scope->options->get('tag', 'latest');

        echo "Pulling {$image}:{$tag}...\n";

        $stream = $scope->await($client->imageCreate($image, null, null, $tag));

        if (is_string($stream)) {
            self::printProgress($stream);
        }

        echo "Done.\n";
        return 0;
    }

    private static function printProgress(string $ndjson): void
    {
        foreach (explode("\n", trim($ndjson)) as $line) {
            $data = json_decode($line, true);
            if ($data === null) {
                continue;
            }
            $status = $data['status'] ?? '';
            $progress = $data['progress'] ?? '';
            echo $progress !== '' ? "{$status} {$progress}\n" : "{$status}\n";
        }
    }
}
