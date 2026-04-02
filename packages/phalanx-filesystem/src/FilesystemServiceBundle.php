<?php

declare(strict_types=1);

namespace Phalanx\Filesystem;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\TaskScope;

final class FilesystemServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(FilePool::class)
            ->factory(static fn() => new FilePool(
                maxOpen: (int) ($context['FILESYSTEM_MAX_OPEN'] ?? 64),
            ));

        $services->scoped(Files::class)
            ->factory(static fn(TaskScope $scope) => new Files($scope));
    }
}
