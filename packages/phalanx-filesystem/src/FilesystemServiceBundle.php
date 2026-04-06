<?php

declare(strict_types=1);

namespace Phalanx\Filesystem;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\TaskScope;

final class FilesystemServiceBundle implements ServiceBundle
{
    public function __construct(
        private readonly ?int $maxOpen = null,
    ) {}

    public function services(Services $services, array $context): void
    {
        $maxOpen = $this->maxOpen ?? (int) ($context['FILESYSTEM_MAX_OPEN'] ?? 64);

        $services->singleton(FilePool::class)
            ->factory(static fn() => new FilePool(
                maxOpen: $maxOpen,
            ));

        $services->scoped(Files::class)
            ->factory(static fn(TaskScope $scope) => new Files($scope));
    }
}
