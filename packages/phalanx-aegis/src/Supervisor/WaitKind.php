<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * Kind axis of a WaitReason. Standard kinds are recorded by framework
 * suspend points so the live task tree can answer "what is this task
 * waiting on?" without speculation. Application code uses Custom with
 * a descriptive detail.
 */
enum WaitKind: string
{
    case Delay = 'delay';
    case Http = 'http';
    case Postgres = 'postgres';
    case Redis = 'redis';
    case Worker = 'worker';
    case Singleflight = 'singleflight';
    case Lock = 'lock';
    case Channel = 'channel';
    case Custom = 'custom';
}
