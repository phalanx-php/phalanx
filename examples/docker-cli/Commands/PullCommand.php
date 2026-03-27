<?php

declare(strict_types=1);

namespace Phalanx\Console\Examples\Commands;

use Clue\React\Docker\Client;
use Phalanx\Console\CommandConfig;
use Phalanx\Console\CommandScope;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

use function React\Async\await;

final class PullCommand implements Scopeable
{
    public CommandConfig $config {
        get => (new CommandConfig())
            ->withDescription('Pull an image')
            ->withArgument('image', 'Image name to pull', required: true)
            ->withOption('tag', shorthand: 't', description: 'Image tag', requiresValue: true, default: 'latest');
    }

    public function __invoke(Scope $scope): int
    {
        assert($scope instanceof CommandScope);

        $client = $scope->service(Client::class);
        $image = $scope->args->required('image');
        $tag = $scope->options->get('tag', 'latest');

        echo "Pulling {$image}:{$tag}...\n";

        $stream = await($client->imageCreate($image, null, null, $tag));

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
