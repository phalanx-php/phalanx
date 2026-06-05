<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Boundaries;

use Phalanx\Tui\Collab\Messages\Address;
use Phalanx\Tui\Collab\Messages\Envelope;

final class InputPromptSubmitter
{
    public function __construct(
        private InletChannel $incoming,
        private ?Address $to = null,
    ) {
    }

    public function __invoke(string $prompt): void
    {
        if (trim($prompt) === '') {
            return;
        }

        $this->incoming->emit(new InletMessage(Envelope::prompt($prompt, $this->to)));
    }
}
