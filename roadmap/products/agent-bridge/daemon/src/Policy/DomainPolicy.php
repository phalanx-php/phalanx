<?php

declare(strict_types=1);

namespace AgentBridge\Policy;

/**
 * Immutable per-domain policy snapshot.
 *
 * Mutations return new instances -- callers pass the result to PolicyStore::save().
 * The action log is capped at 500 entries to bound disk growth.
 */
final readonly class DomainPolicy
{
    public function __construct(
        public string $domain,
        /** @var list<PolicyRule> */
        public array $rules,
        public int $totalActions,
        public int $totalOverrides,
        /** @var list<array{action: string, context: array<string, mixed>, timestamp: string}> */
        public array $userActionLog,
    ) {}

    public static function empty(string $domain): self
    {
        return new self($domain, [], 0, 0, []);
    }

    /** @param array<string, mixed> $action */
    public function withUserAction(array $action): self
    {
        $log = $this->userActionLog;
        $log[] = [
            'action' => $action['action'] ?? 'unknown',
            'context' => $action,
            'timestamp' => date('c'),
        ];

        if (count($log) > 500) {
            $log = array_slice($log, -500);
        }

        return new self($this->domain, $this->rules, $this->totalActions + 1, $this->totalOverrides, $log);
    }

    /** @param array<string, mixed> $context */
    public function withOverride(string $legoName, array $context): self
    {
        return new self($this->domain, $this->rules, $this->totalActions, $this->totalOverrides + 1, $this->userActionLog);
    }

    /** @param list<PolicyRule> $rules */
    public function withRules(array $rules): self
    {
        return new self($this->domain, $rules, $this->totalActions, $this->totalOverrides, $this->userActionLog);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            domain: $data['domain'],
            rules: array_map(static fn(array $r) => PolicyRule::fromArray($r), $data['rules'] ?? []),
            totalActions: $data['totalActions'] ?? 0,
            totalOverrides: $data['totalOverrides'] ?? 0,
            userActionLog: $data['userActionLog'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'rules' => array_map(static fn(PolicyRule $r) => $r->toArray(), $this->rules),
            'totalActions' => $this->totalActions,
            'totalOverrides' => $this->totalOverrides,
            'userActionLog' => $this->userActionLog,
        ];
    }
}
