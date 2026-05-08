<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Styx\Channel;

class Surreal
{
    /** @var array<string, mixed> */
    private array $params = [];

    private ?string $token;

    private ?SurrealLiveConnection $liveConnection = null;

    public function __construct(
        private SurrealConfig $config,
        private readonly SurrealTransport $transport,
        private readonly TaskScope $scope,
        private readonly SurrealScopeGuard $guard = new SurrealScopeGuard(),
        private readonly ?SurrealLiveTransport $liveTransport = null,
    ) {
        $this->token = $config->token;
    }

    public function close(): void
    {
        $this->liveConnection?->close();
        $this->guard->close();
    }

    public function withDatabase(string $namespace, string $database): self
    {
        $this->assertNoLiveConnection('withDatabase');

        $client = new self(
            $this->config->withDatabase($namespace, $database),
            $this->transport,
            $this->scope,
            $this->guard,
            $this->liveTransport,
        );
        $client->token = $this->token;
        $client->params = $this->params;

        return $client;
    }

    /** @param array<string, mixed> $credentials */
    public function signin(array $credentials = []): ?string
    {
        $this->assertNoLiveConnection('signin');
        $payload = $credentials === []
            ? $this->defaultCredentials()
            : $credentials;

        $token = self::extractToken($this->rpcRaw('signin', [$payload]), 'signin');
        $this->token = $token;

        return $token;
    }

    /** @param array<string, mixed> $credentials */
    public function signup(array $credentials): ?string
    {
        $this->assertNoLiveConnection('signup');
        $token = self::extractToken($this->rpcRaw('signup', [$credentials]), 'signup');
        $this->token = $token;

        return $token;
    }

    public function authenticate(string $token): mixed
    {
        $this->assertNoLiveConnection('authenticate');
        $result = $this->rpcRaw('authenticate', [$token]);
        $this->token = $token;

        return $result;
    }

    public function invalidate(): mixed
    {
        $this->assertNoLiveConnection('invalidate');
        $result = $this->rpcRaw('invalidate', token: $this->token);
        $this->token = null;

        return $result;
    }

    public function info(): mixed
    {
        return $this->rpc('info');
    }

    public function ping(): mixed
    {
        return $this->rpc('ping');
    }

    public function reset(): mixed
    {
        $this->assertNoLiveConnection('reset');
        $result = $this->rpcRaw('reset', token: $this->token);
        $this->token = null;
        $this->params = [];

        return $result;
    }

    public function version(): string
    {
        $version = $this->rpc('version');
        if (!is_string($version)) {
            throw new SurrealException('Surreal version returned a non-string response.');
        }

        return $version;
    }

    public function status(): int
    {
        $this->guard->assertOpen();

        return $this->transport->status($this->scope, $this->config, $this->token);
    }

    public function health(): int
    {
        $this->guard->assertOpen();

        return $this->transport->health($this->scope, $this->config, $this->token);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<mixed>|null
     */
    public function queryRaw(string $query, array $params = []): ?array
    {
        $merged = [...$this->params, ...$params];
        $result = $merged === []
            ? $this->rpc('query', [$query])
            : $this->rpc('query', [$query, $merged]);

        return is_array($result) ? array_values($result) : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<mixed>|null
     */
    public function query(string $query, array $params = []): ?array
    {
        $raw = $this->queryRaw($query, $params);
        if ($raw === null) {
            return null;
        }

        return array_map(
            static fn(mixed $item): mixed => is_array($item) && array_key_exists('result', $item)
                ? $item['result']
                : $item,
            $raw,
        );
    }

    /**
     * Store a local query variable merged into subsequent HTTP `query()` calls.
     */
    public function let(string $name, mixed $value): void
    {
        $this->assertNoLiveConnection('let');
        $this->guard->assertOpen();
        $this->params[$name] = $value;
    }

    /**
     * Remove a local query variable from subsequent HTTP `query()` calls.
     */
    public function unset(string $name): void
    {
        $this->assertNoLiveConnection('unset');
        $this->guard->assertOpen();
        unset($this->params[$name]);
    }

    public function use(string $namespace, string $database): mixed
    {
        $this->assertNoLiveConnection('use');
        $result = $this->rpcRaw('use', [$namespace, $database], $this->token);
        $this->config = $this->config->withDatabase($namespace, $database);

        return $result;
    }

    public function select(string $thing): mixed
    {
        return $this->rpc('select', [$thing]);
    }

    public function create(string $thing, mixed $data): mixed
    {
        return $this->rpc('create', [$thing, $data]);
    }

    /** @param array<array-key, mixed> $data */
    public function insert(string $table, array $data): mixed
    {
        return $this->rpc('insert', [$table, $data]);
    }

    /** @param array<array-key, mixed> $data */
    public function insertRelation(string $table, array $data): mixed
    {
        return $this->rpc('insert_relation', [$table, $data]);
    }

    public function update(string $thing, mixed $data): mixed
    {
        return $this->rpc('update', [$thing, $data]);
    }

    public function upsert(string $thing, mixed $data): mixed
    {
        return $this->rpc('upsert', [$thing, $data]);
    }

    public function merge(string $thing, mixed $data): mixed
    {
        return $this->rpc('merge', [$thing, $data]);
    }

    /** @param list<array{op: string, path: string, value?: mixed}> $patches */
    public function patch(string $thing, array $patches, bool $diff = false): mixed
    {
        return $this->rpc('patch', [$thing, $patches, $diff]);
    }

    public function delete(string $thing): mixed
    {
        return $this->rpc('delete', [$thing]);
    }

    public function kill(string $queryUuid): mixed
    {
        return $this->rpc('kill', [$queryUuid]);
    }

    public function live(string $table, bool $diff = false): SurrealLiveSubscription
    {
        $result = $this->liveConnection()->request('live', [$table, $diff]);

        return $this->subscription(self::extractLiveQueryId($result, 'live'));
    }

    /** @param array<string, mixed> $params */
    public function liveQuery(string $query, array $params = []): SurrealLiveSubscription
    {
        $merged = [...$this->params, ...$params];
        $result = $merged === []
            ? $this->liveConnection()->request('query', [$query])
            : $this->liveConnection()->request('query', [$query, $merged]);

        return $this->subscription(self::extractLiveQueryId($result, 'live query'));
    }

    /**
     * @param string|list<string> $from
     * @param string|list<string> $to
     * @param array<string, mixed>|null $data
     */
    public function relate(
        string|array $from,
        string $thing,
        string|array $to,
        ?array $data = null,
    ): mixed {
        return $this->rpc('relate', [$from, $thing, $to, $data]);
    }

    /** @param list<mixed>|null $params */
    public function run(string $function, ?string $version = null, ?array $params = null): mixed
    {
        return $this->rpc('run', [$function, $version, $params]);
    }

    /** @param list<mixed> $params */
    public function rpc(string $method, array $params = []): mixed
    {
        return $this->rpcRaw($method, $params, $this->token);
    }

    private static function extractLiveQueryId(mixed $result, string $method): string
    {
        if (is_string($result)) {
            return $result;
        }

        if (is_array($result)) {
            foreach ($result as $item) {
                if (is_array($item) && isset($item['result']) && is_string($item['result'])) {
                    return $item['result'];
                }
            }
        }

        throw new SurrealException("Surreal {$method} did not return a live query id.");
    }

    /** @param array<array-key, mixed>|string|null $result */
    private static function extractToken(mixed $result, string $method): ?string
    {
        if (is_string($result) || $result === null) {
            return $result;
        }

        if (is_array($result) && array_key_exists('token', $result)) {
            $token = $result['token'];
            if (is_string($token) || $token === null) {
                return $token;
            }
        }

        throw new SurrealException("Surreal {$method} returned a non-token response.");
    }

    private function liveConnection(): SurrealLiveConnection
    {
        $this->guard->assertOpen();

        if ($this->liveConnection?->isOpen === true) {
            return $this->liveConnection;
        }

        if ($this->liveTransport === null) {
            throw new SurrealException('Surreal live queries require a live transport.');
        }

        if (!$this->scope instanceof ExecutionScope) {
            throw new SurrealException('Surreal live queries require an execution scope.');
        }

        $this->liveConnection = $this->liveTransport->open($this->scope, $this->config, $this->token);

        return $this->liveConnection;
    }

    private function subscription(string $queryId): SurrealLiveSubscription
    {
        $channel = new Channel();
        $connection = $this->liveConnection();
        $connection->subscribe($queryId, $channel);

        return new SurrealLiveSubscription($queryId, $connection, $channel);
    }

    private function assertNoLiveConnection(string $method): void
    {
        if ($this->liveConnection?->isOpen === true) {
            throw new SurrealException("Cannot call {$method} while Surreal live subscriptions are open.");
        }
    }

    /** @return array<string, mixed> */
    private function defaultCredentials(): array
    {
        if ($this->config->username === null || $this->config->password === null) {
            throw new SurrealException('Surreal credentials are not configured.');
        }

        return [
            'user' => $this->config->username,
            'pass' => $this->config->password,
        ];
    }

    /** @param list<mixed> $params */
    private function rpcRaw(string $method, array $params = [], ?string $token = null): mixed
    {
        $this->guard->assertOpen();

        return $this->transport->rpc($this->scope, $this->config, $token, $method, $params);
    }
}
