<?php

declare(strict_types=1);

namespace AgentBridge;

use Phalanx\Hermes\WsConnection;

final class ExtensionSession
{
    /** @var array<int, true> */
    private array $tabs = [];

    public function __construct(
        private(set) WsConnection $connection,
    ) {}

    public function send(BridgeCommand $command): void
    {
        $this->connection->sendText($command->toJson());
    }

    public function claimTab(int $tabId): void
    {
        $this->tabs[$tabId] = true;
    }

    public function releaseTab(int $tabId): void
    {
        unset($this->tabs[$tabId]);
    }

    /**
     * @return list<int>
     */
    public function ownedTabIds(): array
    {
        return array_keys($this->tabs);
    }
}
