<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Athena\Message\Message;

final readonly class StepAction
{
    private function __construct(
        public StepActionKind $kind,
        public ?Message $message = null,
        public ?string $finalText = null,
    ) {}

    public static function continue(): self
    {
        return new self(StepActionKind::Continue);
    }

    public static function finalize(string $text): self
    {
        return new self(StepActionKind::Finalize, finalText: $text);
    }

    public static function inject(Message $message): self
    {
        return new self(StepActionKind::Inject, message: $message);
    }
}
