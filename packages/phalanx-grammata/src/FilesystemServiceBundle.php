<?php

declare(strict_types=1);

namespace Phalanx\Grammata;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\Optional;
use Phalanx\Grammata\NativeFastPath\NativeFastPath;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Scope\TaskScope;

class FilesystemServiceBundle extends ServiceBundle
{
    /**
     * File pool size is optional — the bundle defaults to 64 concurrent
     * file handles when the env var is absent.
     */
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(
            Optional::env('FILESYSTEM_MAX_OPEN', fallback: '64', description: 'Maximum concurrent file handles'),
        );
    }


    public function __construct(
        private ?int $maxOpen = null,
    ) {}

    public function services(Services $services, AppContext $context): void
    {
        $maxOpen = $this->maxOpen ?? $context->int('FILESYSTEM_MAX_OPEN', 64);

        $services->singleton(FilePool::class)
            ->factory(static fn() => new FilePool(
                maxOpen: $maxOpen,
            ));

        $services->scoped(Files::class)
            ->factory(static fn(TaskScope $scope) => new Files($scope));

        $services->singleton(NativeFastPath::class)
            ->factory(static fn() => new NativeFastPath());
    }
}
