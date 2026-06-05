<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use WeakMap;

final class Gateway
{
    /** @var WeakMap<\Phalanx\WebSocket\Connection, list<string>> */
    private WeakMap $connections;

    /** @var array<string, WeakMap<\Phalanx\WebSocket\Connection, true>> */
    private array $topics = [];

    public function __construct()
    {
        $this->connections = new WeakMap();
    }

    public function register(\Phalanx\WebSocket\Connection $conn): void
    {
        $this->connections[$conn] = [];
    }

    public function unregister(\Phalanx\WebSocket\Connection $conn): void
    {
        $topics = $this->connections[$conn] ?? [];

        foreach ($topics as $topic) {
            unset($this->topics[$topic][$conn]);

            if (isset($this->topics[$topic]) && count($this->topics[$topic]) === 0) {
                unset($this->topics[$topic]);
            }
        }

        unset($this->connections[$conn]);
    }

    public function subscribe(\Phalanx\WebSocket\Connection $conn, string ...$topics): void
    {
        $current = $this->connections[$conn] ?? [];

        foreach ($topics as $topic) {
            if (!isset($this->topics[$topic])) {
                /** @var WeakMap<\Phalanx\WebSocket\Connection, true> $topicMap */
                $topicMap = new WeakMap();
                $this->topics[$topic] = $topicMap;
            }

            $this->topics[$topic][$conn] = true;

            if (!in_array($topic, $current, true)) {
                $current[] = $topic;
            }
        }

        $this->connections[$conn] = $current;
    }

    public function unsubscribe(\Phalanx\WebSocket\Connection $conn, string ...$topics): void
    {
        $current = $this->connections[$conn] ?? [];

        foreach ($topics as $topic) {
            if (isset($this->topics[$topic])) {
                unset($this->topics[$topic][$conn]);

                if (count($this->topics[$topic]) === 0) {
                    unset($this->topics[$topic]);
                }
            }

            $current = array_values(array_filter(
                $current,
                static fn(string $t) => $t !== $topic,
            ));
        }

        $this->connections[$conn] = $current;
    }

    public function publish(
        string $topic,
        \Phalanx\WebSocket\Message $msg,
        ?\Phalanx\WebSocket\Connection $exclude = null,
    ): void {
        $subscribers = $this->topics[$topic] ?? null;

        if ($subscribers === null) {
            return;
        }

        foreach ($subscribers as $conn => $_) {
            if ($conn === $exclude || !$conn->isOpen) {
                continue;
            }

            $conn->send($msg);
        }
    }

    public function broadcast(\Phalanx\WebSocket\Message $msg, ?\Phalanx\WebSocket\Connection $exclude = null): void
    {
        foreach ($this->connections as $conn => $_) {
            if ($conn === $exclude || !$conn->isOpen) {
                continue;
            }

            $conn->send($msg);
        }
    }

    public function count(): int
    {
        return count($this->connections);
    }

    public function topicCount(string $topic): int
    {
        return isset($this->topics[$topic]) ? count($this->topics[$topic]) : 0;
    }
}
