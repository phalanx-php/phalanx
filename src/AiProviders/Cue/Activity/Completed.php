<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Cue\Activity;

use Phalanx\AiProviders\Cue;

final class Completed extends Cue
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
