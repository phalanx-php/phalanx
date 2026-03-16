<?php

declare(strict_types=1);

namespace Convoy\Integration\Twilio\ConversationRelay;

final class CrMessage
{
    private function __construct(
        public private(set) string $type,
        public private(set) ?string $voicePrompt = null,
        public private(set) ?string $digit = null,
        public private(set) ?string $callSid = null,
        public private(set) ?string $from = null,
        public private(set) ?string $to = null,
        public private(set) ?string $utteranceUntilInterrupt = null,
        public private(set) ?int $durationUntilInterruptMs = null,
        public private(set) ?string $description = null,
        public private(set) ?string $handoffData = null,
        /** @var array<string, mixed>|null */
        public private(set) ?array $customParameters = null,
    ) {}

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $type = $data['type'] ?? 'unknown';

        return new self(
            type: $type,
            voicePrompt: $data['voicePrompt'] ?? null,
            digit: $data['digit'] ?? null,
            callSid: $data['callSid'] ?? null,
            from: $data['from'] ?? null,
            to: $data['to'] ?? null,
            utteranceUntilInterrupt: $data['utteranceUntilInterrupt'] ?? null,
            durationUntilInterruptMs: $data['durationUntilInterruptMs'] ?? null,
            description: $data['description'] ?? null,
            handoffData: $data['handoffData'] ?? null,
            customParameters: $data['customParameters'] ?? null,
        );
    }
}
