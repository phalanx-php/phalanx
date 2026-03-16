<?php

declare(strict_types=1);

namespace Convoy\Console\Examples\Commands;

use Clue\React\Docker\Client;
use Convoy\Console\CommandConfig;
use Convoy\Console\CommandScope;
use Convoy\Scope;
use Convoy\Task\Scopeable;

use function React\Async\await;

final class ImagesCommand implements Scopeable
{
    public CommandConfig $config {
        get => (new CommandConfig())
            ->withDescription('List images');
    }

    public function __invoke(Scope $scope): int
    {
        assert($scope instanceof CommandScope);

        $client = $scope->service(Client::class);
        $images = await($client->imageList());

        if ($images === []) {
            echo "No images.\n";
            return 0;
        }

        self::printImages($images);

        return 0;
    }

    private static function printImages(array $images): void
    {
        $repoW = 40;
        $tagW = 20;

        printf("%-{$repoW}s  %-{$tagW}s  %s\n", 'REPOSITORY', 'TAG', 'SIZE');

        foreach ($images as $img) {
            $repoTags = $img['RepoTags'] ?? ['<none>:<none>'];
            $size = round($img['Size'] / 1_000_000, 1) . 'MB';

            foreach ($repoTags as $repoTag) {
                [$repo, $tag] = self::parseRepoTag($repoTag);
                $repo = strlen($repo) > $repoW ? substr($repo, 0, $repoW - 3) . '...' : $repo;
                printf("%-{$repoW}s  %-{$tagW}s  %s\n", $repo, $tag, $size);
            }
        }
    }

    /** @return array{string, string} */
    private static function parseRepoTag(string $repoTag): array
    {
        [$repo, $tag] = explode(':', $repoTag, 2) + [1 => 'latest'];

        return [$repo, $tag];
    }
}
