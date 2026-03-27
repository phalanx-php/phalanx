<?php

declare(strict_types=1);

namespace Phalanx\Ai\Memory;

use Phalanx\Ai\Message\Conversation;
use Phalanx\Redis\RedisClient;

final class RedisConversationMemory implements ConversationMemory
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly int $ttl = 3600,
        private readonly string $prefix = 'conversation:',
    ) {}

    public function load(string $sessionId): Conversation
    {
        $data = $this->redis->get($this->prefix . $sessionId);

        if ($data === null || $data === false) {
            return Conversation::create();
        }

        $decoded = json_decode((string) $data, true, 512, JSON_THROW_ON_ERROR);

        return Conversation::fromArray($decoded);
    }

    public function save(string $sessionId, Conversation $conversation): void
    {
        $key = $this->prefix . $sessionId;
        $data = json_encode($conversation->toArray(), JSON_THROW_ON_ERROR);

        $this->redis->set($key, $data, $this->ttl);
    }
}
