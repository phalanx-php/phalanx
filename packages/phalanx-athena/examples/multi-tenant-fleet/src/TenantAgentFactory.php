<?php

declare(strict_types=1);

namespace Acme;

use Acme\Tools\TenantKbSearch;
use Acme\Tools\TransferToHuman;
use Phalanx\Athena\AgentDefinition;

/**
 * Builds tenant-specific agents from database configuration.
 *
 * Each tenant gets a custom agent with their own tools, system prompt,
 * provider, and escalation rules. Adding a new tenant is a database insert,
 * not a code deployment.
 *
 * In production: inject PgPool and RedisClient via constructor.
 */
final class TenantAgentFactory
{
    /**
     * @param array<string, array{
     *     system_prompt: string,
     *     provider: string,
     *     model: string,
     *     enabled_tools: list<string>,
     *     escalation: array<string, mixed>
     * }> $tenantConfigs
     */
    public function __construct(
        private readonly array $tenantConfigs = [],
    ) {}

    public function create(string $tenantId): AgentDefinition
    {
        $config = $this->tenantConfigs[$tenantId] ?? [
            'system_prompt' => 'You are a helpful support agent.',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-20250514',
            'enabled_tools' => ['knowledge_base', 'transfer_to_human'],
            'escalation' => [],
        ];

        return new TenantSupportAgent(
            tenantId: $tenantId,
            systemInstructions: $config['system_prompt'],
            providerName: $config['provider'],
            toolClasses: $this->resolveTools($config['enabled_tools']),
        );
    }

    /**
     * @param list<string> $enabledTools
     * @return list<class-string<\Phalanx\Athena\Tool\Tool>>
     */
    private function resolveTools(array $enabledTools): array
    {
        /** @var array<string, class-string<\Phalanx\Athena\Tool\Tool>> $available */
        $available = [
            'knowledge_base' => TenantKbSearch::class,
            'transfer_to_human' => TransferToHuman::class,
        ];

        /** @var list<class-string<\Phalanx\Athena\Tool\Tool>> */
        return array_values(array_intersect_key($available, array_flip($enabledTools)));
    }
}
