<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Response\ResponseLeaseDomain;
use Phalanx\Stoa\Runtime\Identity\StoaResourceSid;
use Phalanx\Task\Scopeable;

/**
 * Returns a snapshot of the runtime's lease and active-resource state so
 * the lifecycle demo can observe the lock-down round's claims (delivery
 * leases released after dispatch, request resources don't accumulate).
 */
final class AdminScope implements Scopeable
{
    /** @return array{response_leases: list<array{key: string}>, request_resources: int} */
    public function __invoke(RequestScope $scope): array
    {
        $leases = [];
        foreach ($scope->runtime->memory->tables->resourceLeases as $row) {
            if (is_array($row) && (string) $row['domain'] === ResponseLeaseDomain::DOMAIN) {
                $leases[] = ['key' => (string) $row['resource_key']];
            }
        }

        // The current request's own resource is still active while this handler
        // runs, so subtract one to report the count of OTHER inflight requests.
        $live = $scope->runtime->memory->resources->liveCount(StoaResourceSid::HttpRequest);
        $other = max(0, $live - 1);

        return [
            'response_leases' => $leases,
            'request_resources' => $other,
        ];
    }
}
