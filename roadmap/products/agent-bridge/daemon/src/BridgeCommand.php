<?php

declare(strict_types=1);

namespace AgentBridge;

final readonly class BridgeCommand
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $type,
        public ?int $tabId = null,
        public ?string $actionId = null,
        public array $payload = [],
    ) {}

    public function toJson(): string
    {
        return json_encode(
            array_filter(
                ['type' => $this->type, 'tabId' => $this->tabId, 'actionId' => $this->actionId, ...$this->payload],
                static fn(mixed $v): bool => $v !== null,
            ),
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    public static function executeAction(int $tabId, string $actionId, array $steps): self
    {
        return new self('action.execute', $tabId, $actionId, ['steps' => $steps]);
    }

    public static function cancelAction(int $tabId, string $actionId): self
    {
        return new self('action.cancel', $tabId, $actionId);
    }

    /**
     * @param array<string, mixed>|null $attrs
     */
    public static function requestDom(int $tabId, string $requestId, string $selector, ?array $attrs = null, ?int $limit = null): self
    {
        return new self('dom.request', $tabId, payload: array_filter([
            'requestId' => $requestId,
            'selector' => $selector,
            'attrs' => $attrs,
            'limit' => $limit,
        ], static fn(mixed $v): bool => $v !== null));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function uiUpdate(string $target, array $data): self
    {
        return new self('ui.update', payload: ['target' => $target, 'data' => $data]);
    }

    public static function throttle(int $tabId, int $maxEventsPerSec): self
    {
        return new self('flow.throttle', $tabId, payload: ['maxEventsPerSec' => $maxEventsPerSec]);
    }

    public static function resume(int $tabId): self
    {
        return new self('flow.resume', $tabId);
    }
}
