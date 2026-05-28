<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Service;

final class Services
{
    /** @var array<class-string, ResourceDescriptor> */
    private(set) array $resources = [];

    /**
     * @param class-string $class
     */
    public function singleton(string $class): ResourceDescriptor
    {
        return $this->resources[$class] ??= new ResourceDescriptor($class);
    }
}
