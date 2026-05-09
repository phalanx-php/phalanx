<?php

declare(strict_types=1);

namespace Phalanx\Actor;

use Phalanx\Task\Traceable;

interface ActorMessage extends \Serializable, Traceable
{
}
