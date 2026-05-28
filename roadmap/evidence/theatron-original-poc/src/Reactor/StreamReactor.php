<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactor;

use Phalanx\Theatron\Stream\TheatronStream;

interface StreamReactor
{
    public function subscribe(TheatronStream $stream, ReactorContext $context): void;
}
