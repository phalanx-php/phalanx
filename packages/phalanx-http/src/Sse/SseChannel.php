<?php

declare(strict_types=1);

namespace Phalanx\Http\Sse;

use React\Stream\WritableStreamInterface;
use WeakMap;

final class SseChannel
{
    /** @var WeakMap<WritableStreamInterface, true> */
    private WeakMap $clients;

    /** @var list<array{id: string, event: ?string, data: string}> */
    private array $buffer = [];

    private int $nextId = 0;

    public function __construct(
        private readonly int $bufferSize = 100,
        private readonly ?string $defaultEvent = null,
    ) {
        $this->clients = new WeakMap();
    }

    public function connect(WritableStreamInterface $client, ?string $lastEventId = null): void
    {
        $this->clients[$client] = true;

        $clients = $this->clients;
        $client->on('close', static function () use ($client, $clients): void {
            unset($clients[$client]);
        });

        if ($lastEventId !== null) {
            $this->replay($client, $lastEventId);
        }
    }

    public function send(string $data, ?string $event = null): void
    {
        $id = (string) ++$this->nextId;
        $event ??= $this->defaultEvent;

        $this->buffer[] = ['id' => $id, 'event' => $event, 'data' => $data];

        if (count($this->buffer) > $this->bufferSize) {
            array_shift($this->buffer);
        }

        $encoded = SseEncoder::encode($data, $event, $id);

        foreach ($this->clients as $client => $_) {
            if ($client->isWritable()) {
                $client->write($encoded);
            }
        }
    }

    public function clientCount(): int
    {
        return count($this->clients);
    }

    private function replay(WritableStreamInterface $client, string $lastEventId): void
    {
        $found = false;

        foreach ($this->buffer as $entry) {
            if (!$found) {
                if ($entry['id'] === $lastEventId) {
                    $found = true;
                }
                continue;
            }

            if ($client->isWritable()) {
                $client->write(SseEncoder::encode($entry['data'], $entry['event'], $entry['id']));
            }
        }
    }
}
