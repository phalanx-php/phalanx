<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Boundaries;

use Phalanx\Theatron\Collab\Messages\Address;
use Phalanx\Theatron\Collab\Messages\Envelope;

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
