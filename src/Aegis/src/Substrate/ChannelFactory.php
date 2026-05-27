<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

interface ChannelFactory
{
    public function create(int $capacity = 0): ChannelHandle;
}
