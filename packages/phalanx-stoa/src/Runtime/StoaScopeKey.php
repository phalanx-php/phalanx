<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Runtime;

enum StoaScopeKey: string
{
    case OpenSwooleResponse = 'stoa.openswoole.response';
    case RequestResource = 'stoa.request_resource';
    case ResourceId = 'stoa.resource_id';
}
