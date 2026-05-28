<?php

declare(strict_types=1);

namespace ThreePath;

final class StbResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $ip,
        public readonly string $command,
        public readonly bool $success,
        public readonly array $data = [],
        public readonly ?string $error = null,
    ) {}

    public bool $timedOut {
        get => $this->error === 'timeout';
    }

    public string $chipId {
        get => (string) ($this->data['stb_chip_id'] ?? '');
    }

    public string $status {
        get => (string) ($this->data['status'] ?? ($this->success ? 'ok' : 'error'));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $this->data['details'][$key] ?? $default;
    }

    public static function timeout(string $ip, string $command): self
    {
        return new self($ip, $command, false, error: 'timeout');
    }

    public static function fromRaw(string $ip, string $command, string $raw): self
    {
        // Strip device_id:msg: prefix if present
        $jsonStart = strpos($raw, '{');
        if ($jsonStart === false) {
            return new self($ip, $command, false, error: 'No JSON in response');
        }

        $json = substr($raw, $jsonStart);

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new self($ip, $command, false, error: "JSON parse error: {$e->getMessage()}");
        }

        $status = $data['status'] ?? null;
        $success = $status === 'success' || $status === 'OK' || isset($data['stb_chip_id']);

        return new self($ip, $command, $success, $data);
    }
}
