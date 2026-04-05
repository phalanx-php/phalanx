<?php

declare(strict_types=1);

namespace Phalanx\Ssh;

final readonly class SshCredential implements \Stringable
{
    public function __construct(
        public string $host,
        public string $user = 'root',
        public int $port = 22,
        public ?string $keyPath = null,
        public ?string $passphrase = null,
        public ?string $configAlias = null,
    ) {}

    public static function fromConfig(string $alias): self
    {
        return new self(host: $alias, configAlias: $alias);
    }

    /**
     * @return list<string>
     */
    public function toConnectionArgs(SshConfig $config): array
    {
        if ($this->configAlias !== null) {
            return [$this->configAlias];
        }

        return [
            '-p', (string) $this->port,
            ...self::commonOptions($config, $this),
            "{$this->user}@{$this->host}",
        ];
    }

    /**
     * @return list<string>
     */
    public function toSftpArgs(SshConfig $config): array
    {
        if ($this->configAlias !== null) {
            return [$this->configAlias];
        }

        return [
            '-P', (string) $this->port,
            ...self::commonOptions($config, $this),
            "{$this->user}@{$this->host}",
        ];
    }

    /**
     * @return list<string>
     */
    public function toScpArgs(SshConfig $config): array
    {
        if ($this->configAlias !== null) {
            return [];
        }

        return [
            '-P', (string) $this->port,
            ...self::commonOptions($config, $this),
        ];
    }

    public function toScpPrefix(): string
    {
        if ($this->configAlias !== null) {
            return "{$this->configAlias}:";
        }

        return "{$this->user}@{$this->host}:";
    }

    public function __toString(): string
    {
        if ($this->configAlias !== null) {
            return $this->configAlias;
        }

        return "{$this->user}@{$this->host}:{$this->port}";
    }

    /**
     * @return list<string>
     */
    private static function commonOptions(SshConfig $config, self $credential): array
    {
        $args = [];

        if ($credential->keyPath !== null) {
            $args[] = '-i';
            $args[] = $credential->keyPath;
        }

        $args[] = '-o';
        $args[] = 'BatchMode=yes';
        $args[] = '-o';
        $args[] = 'ConnectTimeout=' . (int) $config->connectionTimeoutSeconds;

        if (!$config->strictHostKeyChecking) {
            $args[] = '-o';
            $args[] = 'StrictHostKeyChecking=no';
            $args[] = '-o';
            $args[] = 'UserKnownHostsFile=/dev/null';
        }

        return $args;
    }
}
