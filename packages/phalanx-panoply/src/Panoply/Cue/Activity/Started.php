<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Activity;

use Phalanx\Panoply\Cue;

final class Started extends Cue
{
    public string $type { get => 'cue.activity.started'; }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [];
    }
}
