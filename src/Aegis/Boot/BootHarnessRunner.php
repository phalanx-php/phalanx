<?php

declare(strict_types=1);

namespace Phalanx\Boot;

use Phalanx\Boot\Exception\CannotBootException;
use Phalanx\Service\ServiceBundle;
use Phalanx\Themis\Config;
use Phalanx\Themis\ConfigFactory;
use Phalanx\Themis\ConfigReflection;
use Phalanx\Themis\Issue;
use Phalanx\Themis\IssueLevel;
use Phalanx\Themis\ValidationContext;

/**
 * Aggregates BootRequirement declarations from registered bundles plus
 * composer-extra fallbacks, evaluates each against the supplied AppContext,
 * and produces a structured BootHarnessReport. Throws CannotBootException
 * when any Required (or Probe[FailBoot]) fails.
 *
 * Stateless: every call to run() or dryRun() is independent.
 */
class BootHarnessRunner
{
    /**
     * Walk all registered bundle classes, merge their static harness()
     * declarations, optionally layer in composer-extra harnesses, then
     * evaluate and return the structured report.
     *
     * Throws CannotBootException when any entry fails. Callers that need
     * the report without throwing should call dryRun() + throwIfFailed().
     *
     * @param list<ServiceBundle|class-string<ServiceBundle>> $bundles
     */
    public function run(AppContext $context, array $bundles, ?string $vendorDir = null): BootHarnessReport
    {
        $harness = $this
            ->collectBundleHarness($bundles)
            ->merge($this->collectBundleConfigHarness($bundles));

        if ($vendorDir !== null) {
            $harness = $harness->merge($this->collectComposerExtraHarness($vendorDir));
        }

        $report = $this->evaluate($harness, $context);
        $this->throwIfFailed($report);

        return $report;
    }

    /**
     * Evaluate a pre-built BootHarness without bundle iteration.
     * Useful for the --doctor surface and tests that want the report without throwing.
     */
    public function dryRun(AppContext $context, BootHarness $harness): BootHarnessReport
    {
        return $this->evaluate($harness, $context);
    }

    /**
     * @param list<ServiceBundle|class-string<ServiceBundle>> $bundles
     */
    public function contextSchema(array $bundles, ?string $vendorDir = null): ContextSchema
    {
        $schema = $this
            ->collectBundleSchema($bundles)
            ->merge($this->collectBundleConfigSchema($bundles));

        if ($vendorDir !== null) {
            $schema = $schema->merge($this->collectComposerExtraHarness($vendorDir)->contextSchema('composer-extra'));
        }

        return $schema;
    }

    /**
     * Throw CannotBootException if the report carries any failures.
     * Use after a dryRun() when the caller wants control over when the throw happens.
     */
    public function throwIfFailed(BootHarnessReport $report): void
    {
        if ($report->hasFailures()) {
            throw new CannotBootException($report);
        }
    }

    /** @param list<Issue> $issues */
    private static function evaluationForConfigIssues(
        string $config,
        ValidationContext $context,
        array $issues,
    ): BootEvaluation {
        $message = self::renderConfigIssues($config, $issues);

        foreach ($issues as $issue) {
            if ($issue->level === IssueLevel::Error) {
                return BootEvaluation::fail($message, $issue->hint);
            }
        }

        foreach ($issues as $issue) {
            if ($context->strict && $issue->level === IssueLevel::Warning) {
                return BootEvaluation::fail($message, $issue->hint);
            }
        }

        return BootEvaluation::warn($message, $issues[0]->hint);
    }

    /** @param list<Issue> $issues */
    private static function renderConfigIssues(string $config, array $issues): string
    {
        $lines = ["Config {$config} has issues:"];

        foreach ($issues as $issue) {
            $key = $issue->envKey === null ? '' : " {$issue->envKey}";
            $lines[] = sprintf('[%s]%s %s', strtolower($issue->level->name), $key, $issue->message);
        }

        return implode(' ', $lines);
    }

    private static function validationContext(AppContext $context): ValidationContext
    {
        return new ValidationContext(strict: $context->bool('PHALANX_CONFIG_STRICT', false));
    }

    /**
     * @param list<ServiceBundle|class-string<ServiceBundle>> $bundles
     */
    private function collectBundleHarness(array $bundles): BootHarness
    {
        $harness = BootHarness::none();

        foreach ($bundles as $bundle) {
            $class = is_string($bundle) ? $bundle : $bundle::class;
            if (!is_subclass_of($class, ServiceBundle::class)) {
                continue;
            }
            $harness = $harness->merge($class::harness());
        }

        return $harness;
    }

    /**
     * @param list<ServiceBundle|class-string<ServiceBundle>> $bundles
     */
    private function collectBundleSchema(array $bundles): ContextSchema
    {
        $schema = ContextSchema::none();

        foreach ($bundles as $bundle) {
            $class = is_string($bundle) ? $bundle : $bundle::class;
            if (!is_subclass_of($class, ServiceBundle::class)) {
                continue;
            }

            $schema = $schema->merge($class::contextSchema());
        }

        return $schema;
    }

    /**
     * @param list<ServiceBundle|class-string<ServiceBundle>> $bundles
     */
    private function collectBundleConfigHarness(array $bundles): BootHarness
    {
        $requirements = [];

        foreach ($this->collectBundleConfigs($bundles) as $config) {
            $requirements[] = Required::callable(
                static function (AppContext $context) use ($config): BootEvaluation {
                    $validationContext = self::validationContext($context);
                    $result = ConfigFactory::fromContext($context->values)->tryHydrate($config, $validationContext);

                    if ($result->issues === []) {
                        return BootEvaluation::pass("Config {$config} validated.");
                    }

                    return self::evaluationForConfigIssues($config, $validationContext, $result->issues);
                },
                "Config {$config}",
            );
        }

        return BootHarness::of(...$requirements);
    }

    /**
     * @param list<ServiceBundle|class-string<ServiceBundle>> $bundles
     */
    private function collectBundleConfigSchema(array $bundles): ContextSchema
    {
        $schema = ContextSchema::none();
        $reflection = new ConfigReflection();

        foreach ($this->collectBundleConfigs($bundles) as $config) {
            foreach ($reflection->describe($config) as $definition) {
                $keys = [];
                foreach ($definition->entries as $entry) {
                    $key = $entry->required
                        ? ContextKey::required($entry->envKey, $entry->description, $entry->type)
                        : ContextKey::optional($entry->envKey, $entry->default, $entry->description, $entry->type);

                    $keys[] = $key->ownedBy($definition->type);
                }

                $schema = $schema->merge(ContextSchema::of(...$keys));
            }
        }

        return $schema;
    }

    /**
     * @param list<ServiceBundle|class-string<ServiceBundle>> $bundles
     * @return list<class-string<Config>>
     */
    private function collectBundleConfigs(array $bundles): array
    {
        $configs = [];

        foreach ($bundles as $bundle) {
            $class = is_string($bundle) ? $bundle : $bundle::class;
            if (!is_subclass_of($class, ServiceBundle::class)) {
                continue;
            }

            foreach ($class::configs() as $config) {
                $configs[$config] = $config;
            }
        }

        return array_values($configs);
    }

    private function evaluate(BootHarness $harness, AppContext $context): BootHarnessReport
    {
        $passed = [];
        $warned = [];
        $failed = [];

        foreach ($harness->all() as $requirement) {
            $evaluation = $requirement->evaluate($context);
            $entry = new BootEvaluationEntry($requirement, $evaluation);

            if ($evaluation->isFail()) {
                $failed[] = $entry;
            } elseif ($evaluation->isWarn()) {
                $warned[] = $entry;
            } else {
                $passed[] = $entry;
            }
        }

        return new BootHarnessReport($passed, $warned, $failed);
    }

    /**
     * Walk vendor/composer/installed.json for packages declaring
     * extra.phalanx.harness as "ClassName::methodName". Silently skips
     * malformed, missing, or unresolvable entries.
     */
    private function collectComposerExtraHarness(string $vendorDir): BootHarness
    {
        $manifest = $vendorDir . '/composer/installed.json';
        if (!is_file($manifest)) {
            return BootHarness::none();
        }

        $raw = file_get_contents($manifest);
        if ($raw === false) {
            return BootHarness::none();
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
            return BootHarness::none();
        }

        $harness = BootHarness::none();

        foreach ($data['packages'] as $package) {
            $entry = $package['extra']['phalanx']['harness'] ?? null;
            if (!is_string($entry) || !str_contains($entry, '::')) {
                continue;
            }

            [$class, $method] = explode('::', $entry, 2);
            if (!class_exists($class) || !method_exists($class, $method)) {
                continue;
            }

            $result = $class::$method();
            if ($result instanceof BootHarness) {
                $harness = $harness->merge($result);
            }
        }

        return $harness;
    }
}
