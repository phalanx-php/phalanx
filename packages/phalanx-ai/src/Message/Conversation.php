<?php

declare(strict_types=1);

namespace Phalanx\Ai\Message;

final readonly class Conversation
{
    /** @param list<Message> $messages */
    private function __construct(
        public ?string $systemPrompt,
        public array $messages,
    ) {}

    public static function create(): self
    {
        return new self(null, []);
    }

    public function system(string $prompt): self
    {
        return new self($prompt, $this->messages);
    }

    /** @param string|list<Content> $content */
    public function user(string|array $content): self
    {
        return new self($this->systemPrompt, [
            ...$this->messages,
            Message::user($content),
        ]);
    }

    /** @param string|list<Content> $content */
    public function assistant(string|array $content): self
    {
        return new self($this->systemPrompt, [
            ...$this->messages,
            Message::assistant($content),
        ]);
    }

    public function append(Message $message): self
    {
        return new self($this->systemPrompt, [...$this->messages, $message]);
    }

    public function appendToolResult(string $toolCallId, mixed $result): self
    {
        $serialized = is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR);

        return $this->append(Message::toolResult($toolCallId, $serialized));
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /** @return list<array{role: string, content: string|null|list<array<string, mixed>>}> */
    public function toArray(): array
    {
        return array_map(static fn(Message $m) => $m->toArray(), $this->messages);
    }

    /** @param list<array{role: string, content: mixed}> $data */
    public static function fromArray(array $data): self
    {
        $conv = self::create();

        foreach ($data as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            $conv = match ($role) {
                'system' => $conv->system(is_string($content) ? $content : ''),
                'user' => $conv->user($content),
                'assistant' => $conv->assistant($content),
                default => $conv,
            };
        }

        return $conv;
    }
}
