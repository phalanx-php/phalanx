<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

final class LifecycleBoundaryFalsePositiveFixture
{
    public function domainResources(DomainMemory $memory): void
    {
        $memory->resources->open('demo.resource');
        $memory->tables->resources->set('demo', []);
    }
}

final class DomainMemory
{
    public DomainResourceCollection $resources;

    public DomainTables $tables;
}

final class DomainResourceCollection
{
    public function open(string $id): void
    {
    }
}

final class DomainTables
{
    public DomainTable $resources;
}

final class DomainTable
{
    /**
     * @param array<string, mixed> $row
     */
    public function set(string $id, array $row): void
    {
    }
}
