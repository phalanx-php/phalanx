<?php

declare(strict_types=1);

namespace Phalanx\Twilio\ConversationRelay;

final class CrMessage
{
    private function __construct(
        private(set) string $type,
        private(set) ?string $voicePrompt = null,
        private(set) ?string $digit = null,
        private(set) ?string $callSid = null,
        private(set) ?string $from = null,
        private(set) ?string $to = null,
        private(set) ?string $utteranceUntilInterrupt = null,
        private(set) ?int $durationUntilInterruptMs = null,
        private(set) ?string $description = null,
        private(set) ?string $handoffData = null,
        /** @var array<string, mixed>|null */
        private(set) ?array $customParameters = null,
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
