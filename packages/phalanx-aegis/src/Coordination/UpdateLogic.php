<?php

declare(strict_types=1);

namespace Phalanx\Coordination;

interface UpdateLogic extends \Serializable
{
    public function __invoke(mixed $current): mixed;
}
