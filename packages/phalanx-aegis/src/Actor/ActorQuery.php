<?php

declare(strict_types=1);

namespace Phalanx\Actor;

interface ActorQuery extends ActorMessage
{
    public function __invoke(ActorContext $context): mixed;
}
