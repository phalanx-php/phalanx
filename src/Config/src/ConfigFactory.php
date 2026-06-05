<?php

declare(strict_types=1);

namespace Phalanx\Config;

final class ConfigFactory
{
    private ConfigHydrator $hydrator;

    /** @param array<string, mixed> $context */
    private function __construct(array $context)
    {
        $this->hydrator = ConfigHydrator::from($context);
    }

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        return new self($context);
    }

    /**
     * @template T of Config
     * @param class-string<T> $type
     * @return T
     * @throws ConfigHydrationException
     */
    public function hydrate(string $type): Config
    {
        return $this->hydrator->hydrate($type);
    }

    /**
     * @template T of Config
     * @param class-string<T> $type
     */
    public function tryHydrate(string $type, ?ValidationContext $context = null): HydratedConfig
    {
        return $this->hydrator->tryHydrate($type, $context ?? new ValidationContext());
    }
}
