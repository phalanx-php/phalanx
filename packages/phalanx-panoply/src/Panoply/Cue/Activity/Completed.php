<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Activity;

use Phalanx\Panoply\Cue;

class Completed extends Cue
{
    final public string $type { get => 'cue.activity.completed'; }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [];
    }
}
