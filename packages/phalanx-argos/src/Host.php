<?php

declare(strict_types=1);

namespace Phalanx\Argos;

final readonly class Host
{
    /**
     * @param list<string> $services
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $ip,
        public ?string $mac = null,
        public ?string $hostname = null,
        public array $services = [],
        public array $metadata = [],
    ) {}

    public function withMac(string $mac): self
    {
        return new self($this->ip, $mac, $this->hostname, $this->services, $this->metadata);
    }

    public function withHostname(string $hostname): self
    {
        return new self($this->ip, $this->mac, $hostname, $this->services, $this->metadata);
    }

    /** @param array<string, mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        return new self($this->ip, $this->mac, $this->hostname, $this->services, array_merge($this->metadata, $metadata));
    }
}
