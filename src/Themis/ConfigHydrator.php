<?php

declare(strict_types=1);

namespace Phalanx\Themis;

use BackedEnum;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ValueError;

/**
 * @internal Hydrates Config objects from a flat key-value context array.
 *           Use ConfigFactory as the public API.
 */
final readonly class ConfigHydrator
{
    /** @param array<string, mixed> $context */
    private function __construct(private array $context)
    {
    }

    /** @param array<string, mixed> $context */
    public static function from(array $context): self
    {
        return new self($context);
    }

    /**
     * @template T of Config
     * @param class-string<T> $type
     * @return T
     */
    public function hydrate(string $type): Config
    {
        $issues = [];
        $config = $this->build($type, $issues);

        if ($issues !== []) {
            throw new ConfigHydrationException($issues);
        }

        return $config;
    }

    /**
     * @template T of Config
     * @param class-string<T> $type
     */
    public function tryHydrate(string $type, ValidationContext $validationContext = new ValidationContext()): HydratedConfig
    {
        $issues = [];

        try {
            $config = $this->build($type, $issues);
        } catch (ConfigHydrationException $exception) {
            return new HydratedConfig(null, $exception->issues);
        }

        $issues = [...$issues, ...$config->validate($validationContext)];

        return new HydratedConfig($config, $issues);
    }

    /**
     * @template T of Config
     * @param class-string<T> $type
     * @param list<Issue> $issues
     * @return T
     */
    private function build(string $type, array &$issues): Config
    {
        $reflection = new ReflectionClass($type);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            /** @var T $config */
            $config = $reflection->newInstance();

            return $config;
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[$parameter->getName()] = $this->valueFor($parameter, $issues);
        }

        /** @var T $config */
        $config = $reflection->newInstanceArgs($arguments);

        return $config;
    }

    /** @param list<Issue> $issues */
    private function valueFor(ReflectionParameter $parameter, array &$issues): mixed
    {
        $env = Env::fromParameter($parameter);
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            $issues[] = new Issue(
                IssueLevel::Error,
                'config.untyped',
                "Config parameter \${$parameter->getName()} must declare a named type.",
                path: $parameter->getName(),
            );

            throw new ConfigHydrationException($issues);
        }

        $typeName = $type->getName();
        if ($env === null && class_exists($typeName) && is_subclass_of($typeName, Config::class)) {
            /** @var class-string<Config> $nested */
            $nested = $typeName;

            return $this->build($nested, $issues);
        }

        if ($env === null) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            $issues[] = new Issue(
                IssueLevel::Error,
                'config.missing-env-metadata',
                "Config parameter \${$parameter->getName()} must use #[Env] or be another Config object.",
                path: $parameter->getName(),
            );

            throw new ConfigHydrationException($issues);
        }

        $hasKey = array_key_exists($env->key, $this->context);
        $rawValue = $this->context[$env->key] ?? null;

        if (!$hasKey || $rawValue === '') {
            if ($typeName === Secret::class) {
                return Secret::empty();
            }

            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($type->allowsNull()) {
                return null;
            }

            $issues[] = new Issue(
                IssueLevel::Error,
                'config.env-missing',
                "Missing required environment value {$env->key}.",
                envKey: $env->key,
                path: $parameter->getName(),
            );

            throw new ConfigHydrationException($issues);
        }

        return $this->coerce(
            $rawValue,
            $typeName,
            $type->allowsNull(),
            $env->key,
            $parameter->getName(),
            $issues,
        );
    }

    /** @param list<Issue> $issues */
    private function coerce(
        mixed $value,
        string $typeName,
        bool $allowsNull,
        string $envKey,
        string $path,
        array &$issues,
    ): mixed {
        if ($typeName === Secret::class) {
            return $value === null ? Secret::empty() : new Secret((string) $value);
        }

        if ($value === null) {
            if ($allowsNull) {
                return null;
            }

            $issues[] = new Issue(
                IssueLevel::Error,
                'config.env-null',
                "Environment value {$envKey} cannot be null.",
                envKey: $envKey,
                path: $path,
            );

            throw new ConfigHydrationException($issues);
        }

        if (class_exists($typeName) && is_subclass_of($typeName, BackedEnum::class)) {
            /** @var class-string<BackedEnum> $enum */
            $enum = $typeName;

            try {
                return $enum::from((string) $value);
            } catch (ValueError) {
                $issues[] = new Issue(
                    IssueLevel::Error,
                    'config.env-enum',
                    "Environment value {$envKey} is not a valid {$typeName} value.",
                    envKey: $envKey,
                    path: $path,
                );

                throw new ConfigHydrationException($issues);
            }
        }

        return match ($typeName) {
            'string' => (string) $value,
            'int' => $this->integer($value, $envKey, $path, $issues),
            'float' => $this->floating($value, $envKey, $path, $issues),
            'bool' => $this->boolean($value, $envKey, $path, $issues),
            default => $value,
        };
    }

    /** @param list<Issue> $issues */
    private function integer(mixed $value, string $envKey, string $path, array &$issues): int
    {
        $filtered = filter_var($value, FILTER_VALIDATE_INT);
        if ($filtered !== false) {
            return $filtered;
        }

        $issues[] = new Issue(IssueLevel::Error, 'config.env-int', "{$envKey} must be an integer.", $envKey, $path);

        throw new ConfigHydrationException($issues);
    }

    /** @param list<Issue> $issues */
    private function floating(mixed $value, string $envKey, string $path, array &$issues): float
    {
        $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($filtered !== false) {
            return $filtered;
        }

        $issues[] = new Issue(IssueLevel::Error, 'config.env-float', "{$envKey} must be a float.", $envKey, $path);

        throw new ConfigHydrationException($issues);
    }

    /** @param list<Issue> $issues */
    private function boolean(mixed $value, string $envKey, string $path, array &$issues): bool
    {
        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($filtered !== null) {
            return $filtered;
        }

        $issues[] = new Issue(IssueLevel::Error, 'config.env-bool', "{$envKey} must be a boolean.", $envKey, $path);

        throw new ConfigHydrationException($issues);
    }
}
