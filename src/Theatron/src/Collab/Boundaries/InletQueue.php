<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Boundaries;

final class InletQueue implements InletChannel
{
    /** @var list<InletMessage> */
    private array $messages = [];

    public function emit(InletMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function hasPending(): bool
    {
        return $this->messages !== [];
    }

    /**
     * @return list<InletMessage>
     */
    public function drain(): array
    {
        $messages = $this->messages;
        $this->messages = [];

        return $messages;
    }
}
