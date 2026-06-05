<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Cue\Invocation;

use Phalanx\AiProviders\Cue;

final class Started extends Cue
{
    final public string $type { get => 'cue.invocation.started'; }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [];
    }
}
