<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Scope\ExecutionScope;

class SurrealClient
{
    /** @var array<string, mixed> */
    private array $params = [];

    private ?string $token;

    public function __construct(
        private readonly SurrealConfig $config,
        private readonly SurrealTransport $transport,
    ) {
        $this->token = $config->token;
    }

    public function withDatabase(string $namespace, string $database): self
    {
        $client = new self($this->config->withDatabase($namespace, $database), $this->transport);
        $client->token = $this->token;
        $client->params = $this->params;

        return $client;
    }

    /** @param array<string, mixed> $credentials */
    public function signin(ExecutionScope $scope, array $credentials = []): ?string
    {
        $payload = $credentials === []
            ? $this->defaultCredentials()
            : $credentials;

        $token = $this->rpcRaw($scope, 'signin', [$payload]);
        if (!is_string($token) && $token !== null) {
            throw new SurrealException('Surreal signin returned a non-token response.');
        }

        $this->token = $token;
        return $token;
    }

    /** @param array<string, mixed> $credentials */
    public function signup(ExecutionScope $scope, array $credentials): ?string
    {
        $token = $this->rpcRaw($scope, 'signup', [$credentials]);
        if (!is_string($token) && $token !== null) {
            throw new SurrealException('Surreal signup returned a non-token response.');
        }

        $this->token = $token;
        return $token;
    }

    public function authenticate(ExecutionScope $scope, string $token): mixed
    {
        $result = $this->rpcRaw($scope, 'authenticate', [$token]);
        $this->token = $token;

        return $result;
    }

    public function invalidate(ExecutionScope $scope): mixed
    {
        $result = $this->rpcRaw($scope, 'invalidate', token: $this->token);
        $this->token = null;

        return $result;
    }

    public function info(ExecutionScope $scope): mixed
    {
        return $this->rpc($scope, 'info');
    }

    public function version(ExecutionScope $scope): string
    {
        $version = $this->rpc($scope, 'version');
        if (!is_string($version)) {
            throw new SurrealException('Surreal version returned a non-string response.');
        }

        return $version;
    }

    public function status(ExecutionScope $scope): int
    {
        return $this->transport->status($scope, $this->config, $this->token);
    }

    public function health(ExecutionScope $scope): int
    {
        return $this->transport->health($scope, $this->config, $this->token);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<mixed>|null
     */
    public function queryRaw(ExecutionScope $scope, string $query, array $params = []): ?array
    {
        $merged = [...$this->params, ...$params];
        $result = $merged === []
            ? $this->rpc($scope, 'query', [$query])
            : $this->rpc($scope, 'query', [$query, $merged]);

        return is_array($result) ? array_values($result) : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<mixed>|null
     */
    public function query(ExecutionScope $scope, string $query, array $params = []): ?array
    {
        $raw = $this->queryRaw($scope, $query, $params);
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

    public function let(string $name, mixed $value): void
    {
        $this->params[$name] = $value;
    }

    public function unset(string $name): void
    {
        unset($this->params[$name]);
    }

    public function select(ExecutionScope $scope, string $thing): mixed
    {
        return $this->rpc($scope, 'select', [$thing]);
    }

    public function create(ExecutionScope $scope, string $thing, mixed $data): mixed
    {
        return $this->rpc($scope, 'create', [$thing, $data]);
    }

    /** @param array<array-key, mixed> $data */
    public function insert(ExecutionScope $scope, string $table, array $data): mixed
    {
        return $this->rpc($scope, 'insert', [$table, $data]);
    }

    /** @param array<array-key, mixed> $data */
    public function insertRelation(ExecutionScope $scope, string $table, array $data): mixed
    {
        return $this->rpc($scope, 'insert_relation', [$table, $data]);
    }

    public function update(ExecutionScope $scope, string $thing, mixed $data): mixed
    {
        return $this->rpc($scope, 'update', [$thing, $data]);
    }

    public function upsert(ExecutionScope $scope, string $thing, mixed $data): mixed
    {
        return $this->rpc($scope, 'upsert', [$thing, $data]);
    }

    public function merge(ExecutionScope $scope, string $thing, mixed $data): mixed
    {
        return $this->rpc($scope, 'merge', [$thing, $data]);
    }

    /** @param list<array{op: string, path: string, value?: mixed}> $patches */
    public function patch(ExecutionScope $scope, string $thing, array $patches, bool $diff = false): mixed
    {
        return $this->rpc($scope, 'patch', [$thing, $patches, $diff]);
    }

    public function delete(ExecutionScope $scope, string $thing): mixed
    {
        return $this->rpc($scope, 'delete', [$thing]);
    }

    /**
     * @param string|list<string> $from
     * @param string|list<string> $to
     * @param array<string, mixed>|null $data
     */
    public function relate(
        ExecutionScope $scope,
        string|array $from,
        string $thing,
        string|array $to,
        ?array $data = null,
    ): mixed {
        return $this->rpc($scope, 'relate', [$from, $thing, $to, $data]);
    }

    /** @param list<mixed>|null $params */
    public function run(ExecutionScope $scope, string $function, ?string $version = null, ?array $params = null): mixed
    {
        return $this->rpc($scope, 'run', [$function, $version, $params]);
    }

    /** @param list<mixed> $params */
    public function rpc(ExecutionScope $scope, string $method, array $params = []): mixed
    {
        return $this->rpcRaw($scope, $method, $params, $this->token);
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
    private function rpcRaw(ExecutionScope $scope, string $method, array $params = [], ?string $token = null): mixed
    {
        return $this->transport->rpc($scope, $this->config, $token, $method, $params);
    }
}
