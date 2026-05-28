<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Slice;

final class ConversationMessage
{
    public function __construct(
        private(set) string $from,
        private(set) string $body,
        private(set) float $timestamp,
        private(set) string $role = 'agent',
    ) {
    }
}
