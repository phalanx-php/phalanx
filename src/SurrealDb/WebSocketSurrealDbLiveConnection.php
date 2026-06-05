<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

use JsonException;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Channel;
use Throwable;

class WebSocketSurrealDbLiveConnection implements SurrealDbLiveConnection
{
    private(set) bool $isOpen = true;

    /** @var array<int, Channel> */
    private array $pending = [];

    /** @var array<string, Channel> */
    private array $subscriptions = [];

    private int $nextId = 1;

    public function __construct(
        private readonly ExecutionScope $scope,
        private readonly SurrealDbLiveSocket $socket,
        private readonly float $requestTimeout,
    ) {
        $connection = $this;

        $scope->go(
            static function () use ($connection): void {
                $connection->readLoop();
            },
            'surrealdb.live.read',
        );

        $scope->onDispose(static function () use ($connection): void {
            $connection->close();
        });
    }

    public function request(string $method, array $params = []): mixed
    {
        $this->assertOpen();

        $id = $this->nextId++;
        $reply = new Channel(1);
        $this->pending[$id] = $reply;

        try {
            $this->socket->sendJson([
                'id' => $id,
                'method' => $method,
                'params' => $params,
            ]);

            $message = $reply->next($this->requestTimeout);
            if ($message === null) {
                unset($this->pending[$id]);

                throw new SurrealDbException("SurrealDb live RPC {$method} timed out.");
            }

            return is_array($message) && array_key_exists('result', $message) ? $message['result'] : $message;
        } finally {
            unset($this->pending[$id]);
            $reply->complete();
        }
    }

    public function subscribe(string $queryId, Channel $channel): void
    {
        $this->assertOpen();
        $this->subscriptions[$queryId] = $channel;
    }

    public function unsubscribe(string $queryId): void
    {
        unset($this->subscriptions[$queryId]);
    }

    public function close(): void
    {
        if (!$this->isOpen) {
            return;
        }

        $this->isOpen = false;
        foreach ($this->pending as $reply) {
            $reply->complete();
        }
        foreach ($this->subscriptions as $channel) {
            $channel->complete();
        }
        $this->pending = [];
        $this->subscriptions = [];
        $this->socket->close();
    }

    public function readLoop(): void
    {
        try {
            foreach ($this->socket->messages() as $message) {
                $this->scope->throwIfCancelled();
                if (!$message->isText) {
                    continue;
                }

                $this->handleEnvelope($message->decode());
            }
        } catch (Cancelled $e) {
            $this->fail($e);

            throw $e;
        } catch (Throwable $e) {
            $this->fail($e);
        } finally {
            $this->close();
        }
    }

    private function handleEnvelope(mixed $envelope): void
    {
        if (!is_array($envelope) || array_is_list($envelope)) {
            throw new SurrealDbException('SurrealDb live RPC envelope was not a JSON object.');
        }

        if (array_key_exists('id', $envelope)) {
            $this->handleReply($envelope);

            return;
        }

        $result = $envelope['result'] ?? null;
        if (!is_array($result)) {
            throw new SurrealDbException('SurrealDb live notification result was missing.');
        }

        $notification = SurrealDbLiveNotification::fromPayload($result);
        $channel = $this->subscriptions[$notification->queryId] ?? null;
        if ($channel !== null) {
            if (!$channel->tryEmit($notification)) {
                unset($this->subscriptions[$notification->queryId]);
                $channel->error(new SurrealDbException(sprintf(
                    'SurrealDb live subscription %s buffer is full.',
                    $notification->queryId,
                )));
            }
        }
    }

    /** @param array<array-key, mixed> $envelope */
    private function handleReply(array $envelope): void
    {
        $id = $envelope['id'];
        if (!is_int($id)) {
            throw new SurrealDbException('SurrealDb live RPC response id was invalid.');
        }

        $reply = $this->pending[$id] ?? null;
        if ($reply === null) {
            return;
        }

        if (array_key_exists('error', $envelope)) {
            $reply->error(SurrealDbException::fromErrorEnvelope($envelope['error']));

            return;
        }

        if (!array_key_exists('result', $envelope)) {
            $reply->error(new SurrealDbException('SurrealDb live RPC response was missing result or error.'));

            return;
        }

        $reply->emit(['result' => $envelope['result']]);
    }

    private function fail(Throwable $error): void
    {
        $exception = $error instanceof JsonException
            ? new SurrealDbException("Failed to decode SurrealDb live message: {$error->getMessage()}", previous: $error)
            : $error;

        foreach ($this->pending as $reply) {
            $reply->error($exception);
        }
        foreach ($this->subscriptions as $channel) {
            $channel->error($exception);
        }
    }

    private function assertOpen(): void
    {
        if (!$this->isOpen) {
            throw new SurrealDbException('SurrealDb live connection is closed.');
        }
    }
}
