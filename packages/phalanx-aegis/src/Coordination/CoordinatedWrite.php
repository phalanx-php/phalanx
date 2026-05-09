<?php

declare(strict_types=1);

namespace Phalanx\Coordination;

use Phalanx\Task\Traceable;

interface CoordinatedWrite extends \Serializable, Traceable
{
    public function getRefName(): string;

    public function getUpdate(): UpdateLogic;

    public function getContext(): ?WriteContext;
}
