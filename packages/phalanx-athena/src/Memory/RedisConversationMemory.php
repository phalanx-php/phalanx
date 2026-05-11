<?php

declare(strict_types=1);

namespace Phalanx\Athena\Memory;

use Phalanx\Athena\Message\Conversation;

final class RedisConversationMemory implements ConversationMemory
{
    /**
     * @var object Holds a Phalanx\Redis\RedisClient instance.
     *             The redis package is optional; presence is enforced at construction time.
     */
    private(set) object $redis;

    public function __construct(
        object $redis,
        private(set) int $ttl = 3600,
        private(set) string $prefix = 'conversation:',
    ) {
        if (!class_exists('Phalanx\Redis\RedisClient')) {
            throw new \RuntimeException(
                'phalanx-php/redis is required to use RedisConversationMemory. '
                . 'Install it via: composer require phalanx-php/redis',
            );
        }

        if (!$redis instanceof \Phalanx\Redis\RedisClient) {
            throw new \InvalidArgumentException(sprintf(
                '%s expects a %s instance, got %s.',
                self::class,
                \Phalanx\Redis\RedisClient::class,
                get_class($redis),
            ));
        }

        $this->redis = $redis;
    }

    public function load(string $sessionId): Conversation
    {
        /** @phpstan-ignore method.notFound */
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

        /** @phpstan-ignore method.notFound */
        $this->redis->set($key, $data, $this->ttl);
    }
}
