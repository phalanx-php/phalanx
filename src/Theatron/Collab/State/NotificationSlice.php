<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\State;

final class NotificationSlice
{
    /** @var list<string> */
    private(set) array $messages;

    /**
     * @param list<string> $messages
     */
    public function __construct(array $messages = [])
    {
        $this->messages = array_values($messages);
    }
}
