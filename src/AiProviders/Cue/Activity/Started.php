<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Cue\Activity;

use Phalanx\AiProviders\Cue;

final class Started extends Cue
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
