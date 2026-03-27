<?php

declare(strict_types=1);

namespace Phalanx\Twilio;

final class TwiML
{
    /** @var list<string> */
    private array $verbs = [];

    public static function response(): self
    {
        return new self();
    }

    public function say(string $text, ?string $voice = null): self
    {
        $attrs = $voice !== null ? " voice=\"{$this->escape($voice)}\"" : '';
        $this->verbs[] = "<Say{$attrs}>{$this->escape($text)}</Say>";
        return $this;
    }

    public function play(string $url): self
    {
        $this->verbs[] = "<Play>{$this->escape($url)}</Play>";
        return $this;
    }

    public function pause(int $length = 1): self
    {
        $this->verbs[] = "<Pause length=\"{$length}\"/>";
        return $this;
    }

    public function hangup(): self
    {
        $this->verbs[] = '<Hangup/>';
        return $this;
    }

    public function redirect(string $url): self
    {
        $this->verbs[] = "<Redirect>{$this->escape($url)}</Redirect>";
        return $this;
    }

    public function conversationRelay(
        string $url,
        ?string $welcomeGreeting = null,
        string $ttsProvider = 'Amazon',
        string $transcriptionProvider = 'Deepgram',
        ?string $voice = null,
        string $language = 'en-US',
        bool $interruptible = true,
        bool $dtmfDetection = true,
    ): self {
        $attrs = [
            'url' => $url,
            'ttsProvider' => $ttsProvider,
            'transcriptionProvider' => $transcriptionProvider,
            'language' => $language,
            'interruptible' => $interruptible ? 'true' : 'false',
            'dtmfDetection' => $dtmfDetection ? 'true' : 'false',
        ];

        if ($welcomeGreeting !== null) {
            $attrs['welcomeGreeting'] = $welcomeGreeting;
        }
        if ($voice !== null) {
            $attrs['voice'] = $voice;
        }

        $attrStr = '';
        foreach ($attrs as $key => $value) {
            $attrStr .= " {$key}=\"{$this->escape($value)}\"";
        }

        $this->verbs[] = "<Connect><ConversationRelay{$attrStr}/></Connect>";
        return $this;
    }

    public function build(): string
    {
        $inner = implode('', $this->verbs);
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response>{$inner}</Response>";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
