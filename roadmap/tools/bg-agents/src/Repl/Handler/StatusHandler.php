<?php

declare(strict_types=1);

namespace BgAgents\Repl\Handler;

use BgAgents\Config\BgAgentsConfig;
use BgAgents\Daemon8\BgEvent;
use BgAgents\Daemon8\ObservationClient;
use BgAgents\Daemon8\ObservationQuery;
use BgAgents\Repl\ReplPrinter;
use BgAgents\Specialist\SpecialistRegistry;
use Phalanx\ExecutionScope;

final readonly class StatusHandler
{
    public function __construct(
        public ObservationClient $client,
        public BgAgentsConfig $config,
        public SpecialistRegistry $registry,
        public ReplPrinter $printer,
    ) {}

    public function handle(ExecutionScope $scope): void
    {
        $this->printer->kv('workspace', $this->config->workspace);
        $this->printer->kv('session', $this->config->session);
        $this->printer->kv('daemon8', $this->config->daemon8Url);
        $this->printer->kv('specialists', (string) count($this->registry->all()));

        try {
            $result = $scope->await($this->client->observe(new ObservationQuery(
                kinds: ['custom'],
                origins: ["app:{$this->config->app}"],
                limit: 50,
            )));
        } catch (\Throwable $e) {
            $this->printer->warn("status query failed: {$e->getMessage()}");
            return;
        }

        $heartbeat = null;
        foreach ($result['observations'] as $rec) {
            if ($rec->bgKind() === BgEvent::TEAM_HEARTBEAT) {
                $heartbeat = $rec;
                break;
            }
        }

        if ($heartbeat === null) {
            $this->printer->kv('heartbeat', '(none seen yet)');
        } else {
            $ageSec = max(0.0, microtime(true) - ($heartbeat->timestampNs / 1e9));
            $this->printer->kv('last heartbeat', sprintf('%.1fs ago (id %d)', $ageSec, $heartbeat->id));
        }
    }
}
