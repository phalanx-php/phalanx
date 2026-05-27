<?php

declare(strict_types=1);

namespace Phalanx\Substrate\Swoole;

use Phalanx\Substrate\ChannelFactory;
use Phalanx\Substrate\ChannelHandle;

final class SwooleChannelFactory implements ChannelFactory
{
    public function create(int $capacity = 0): ChannelHandle
    {
        return new SwooleChannelHandle($capacity);
    }
}
