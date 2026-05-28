<?php

declare(strict_types=1);

namespace Sentinel\Watcher;

enum ChangeKind: string
{
    case Created = 'created';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
}