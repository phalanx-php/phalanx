<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Activity;

use Phalanx\Panoply\Cue;

class Started extends Cue
{
    final public string $type { get => 'cue.activity.started'; }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [];
    }
}
