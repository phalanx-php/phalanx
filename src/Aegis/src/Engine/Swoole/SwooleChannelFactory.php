<?php

declare(strict_types=1);

namespace Phalanx\Engine\Swoole;

use Phalanx\Engine\ChannelFactory;
use Phalanx\Engine\ChannelHandle;

final class SwooleChannelFactory implements ChannelFactory
{
    public function create(int $capacity = 0): ChannelHandle
    {
        return new SwooleChannelHandle($capacity);
    }
}
