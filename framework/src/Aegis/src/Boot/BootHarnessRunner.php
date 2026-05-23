<?php

declare(strict_types=1);

namespace Phalanx\Boot;

use Phalanx\Boot\Exception\CannotBootException;
use Phalanx\Service\ServiceBundle;

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
        $harness = $this->collectBundleHarness($bundles);

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
     * Throw CannotBootException if the report carries any failures.
     * Use after a dryRun() when the caller wants control over when the throw happens.
     */
    public function throwIfFailed(BootHarnessReport $report): void
    {
        if ($report->hasFailures()) {
            throw new CannotBootException($report);
        }
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
