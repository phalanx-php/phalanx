<?php

declare(strict_types=1);

namespace AgentBridge;

final class BridgeMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private(set) string $type,
        private(set) ?int $tabId = null,
        private(set) ?string $url = null,
        private(set) ?string $title = null,
        private(set) ?string $domain = null,
        /** @var array<string, mixed> */
        private(set) array $payload = [],
        private(set) ?float $timestamp = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromJson(array $data): self
    {
        $type = $data['type'] ?? throw new \InvalidArgumentException('Missing message type');
        $url = $data['url'] ?? null;

        // domain from wire takes precedence; fall back to parsing url
        $domain = $data['domain'] ?? null;
        if ($domain === null && $url !== null) {
            $domain = parse_url($url, PHP_URL_HOST) ?: null;
        }

        return new self(
            type: $type,
            tabId: isset($data['tabId']) ? (int) $data['tabId'] : null,
            url: $url,
            title: $data['title'] ?? null,
            domain: $domain,
            payload: array_diff_key($data, array_flip(['type', 'tabId', 'url', 'title', 'domain', 'timestamp'])),
            timestamp: isset($data['timestamp']) ? (float) $data['timestamp'] : null,
        );
    }
}
