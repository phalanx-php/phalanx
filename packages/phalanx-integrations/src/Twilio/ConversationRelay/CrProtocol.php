<?php

declare(strict_types=1);

namespace Phalanx\Integration\Twilio\ConversationRelay;

final class CrProtocol
{
    public static function text(string $token, bool $last = false): string
    {
        return self::encode([
            'type' => 'text',
            'token' => $token,
            'last' => $last,
        ]);
    }

    public static function endSession(?string $handoffData = null): string
    {
        $msg = ['type' => 'end'];
        if ($handoffData !== null) {
            $msg['handoffData'] = $handoffData;
        }
        return self::encode($msg);
    }

    public static function sendDigits(string $digits): string
    {
        return self::encode([
            'type' => 'sendDigits',
            'digits' => $digits,
        ]);
    }

    public static function play(string $url, bool $interruptible = true): string
    {
        return self::encode([
            'type' => 'play',
            'source' => $url,
            'interruptible' => $interruptible,
        ]);
    }

    public static function language(string $ttsLanguage, ?string $transcriptionLanguage = null): string
    {
        $msg = [
            'type' => 'language',
            'ttsLanguage' => $ttsLanguage,
        ];
        if ($transcriptionLanguage !== null) {
            $msg['transcriptionLanguage'] = $transcriptionLanguage;
        }
        return self::encode($msg);
    }

    public static function decode(string $json): CrMessage
    {
        return CrMessage::fromJson($json);
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
