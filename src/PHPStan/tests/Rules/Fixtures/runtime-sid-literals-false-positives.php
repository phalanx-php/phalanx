<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Runtime\QueryScope;

final class RuntimeSidLiteralFalsePositiveFixture
{
    public function domainCollection(DomainCatalog $catalog): void
    {
        $catalog->all('http.request');
        $catalog->liveCount('runtime.scope');
    }

    public function queryResourceById(QueryScope $query): void
    {
        $query->get('runtime.scope');
    }
}

final class DomainCatalog
{
    /** @return list<string> */
    public function all(string $type): array
    {
        return [$type];
    }

    public function liveCount(string $type): int
    {
        return strlen($type);
    }
}
