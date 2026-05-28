<?php

declare(strict_types=1);

namespace AgentBridge\Policy;

/**
 * File-based policy storage.
 *
 * One JSON file per domain at {basePath}/{safe-domain}.json.
 * Reads are eager (no caching) -- policies are small and infrequent enough
 * that the overhead is negligible compared to AI call latency.
 */
final class PolicyStore
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function forDomain(string $domain): DomainPolicy
    {
        $file = $this->policyPath($domain);

        if (!file_exists($file)) {
            return DomainPolicy::empty($domain);
        }

        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return DomainPolicy::fromArray($data);
    }

    public function save(DomainPolicy $policy): void
    {
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }

        file_put_contents(
            $this->policyPath($policy->domain),
            json_encode($policy->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }

    /** @param array<string, mixed> $action */
    public function recordUserAction(string $domain, array $action): void
    {
        $this->save($this->forDomain($domain)->withUserAction($action));
    }

    /** @param array<string, mixed> $context */
    public function recordOverride(string $domain, string $legoName, array $context): void
    {
        $this->save($this->forDomain($domain)->withOverride($legoName, $context));
    }

    private function policyPath(string $domain): string
    {
        $safeDomain = str_replace(['/', '\\', '..'], '_', $domain);

        return $this->basePath . "/{$safeDomain}.json";
    }
}
