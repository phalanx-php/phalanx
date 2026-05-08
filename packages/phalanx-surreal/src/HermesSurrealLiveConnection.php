<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use JsonException;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Throwable;

class HermesSurrealLiveConnection implements SurrealLiveConnection
{
    public bool $isOpen {
        get => $this->open;
    }

    /** @var array<int, Channel> */
    private array $pending = [];

    /** @var array<string, Channel> */
    private array $subscriptions = [];

    private int $nextId = 1;

    private bool $open = true;

    public function __construct(
        private readonly ExecutionScope $scope,
        private readonly SurrealLiveSocket $socket,
        private readonly float $requestTimeout,
    ) {
        $connection = $this;

        $scope->go(
            static function () use ($connection): void {
                $connection->readLoop();
            },
            'surreal.live.read',
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
                throw new SurrealException("Surreal live RPC {$method} timed out.");
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
        if (!$this->open) {
            return;
        }

        $this->open = false;
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
            throw new SurrealException('Surreal live RPC envelope was not a JSON object.');
        }

        if (array_key_exists('id', $envelope)) {
            $this->handleReply($envelope);
            return;
        }

        $result = $envelope['result'] ?? null;
        if (!is_array($result)) {
            throw new SurrealException('Surreal live notification result was missing.');
        }

        $notification = SurrealLiveNotification::fromPayload($result);
        $channel = $this->subscriptions[$notification->queryId] ?? null;
        if ($channel !== null) {
            if (!$channel->tryEmit($notification)) {
                unset($this->subscriptions[$notification->queryId]);
                $channel->error(new SurrealException(sprintf(
                    'Surreal live subscription %s buffer is full.',
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
            throw new SurrealException('Surreal live RPC response id was invalid.');
        }

        $reply = $this->pending[$id] ?? null;
        if ($reply === null) {
            return;
        }

        if (array_key_exists('error', $envelope)) {
            $reply->error(SurrealException::fromErrorEnvelope($envelope['error']));
            return;
        }

        if (!array_key_exists('result', $envelope)) {
            $reply->error(new SurrealException('Surreal live RPC response was missing result or error.'));
            return;
        }

        $reply->emit(['result' => $envelope['result']]);
    }

    private function fail(Throwable $error): void
    {
        $exception = $error instanceof JsonException
            ? new SurrealException("Failed to decode Surreal live message: {$error->getMessage()}", previous: $error)
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
        if (!$this->open) {
            throw new SurrealException('Surreal live connection is closed.');
        }
    }
}
