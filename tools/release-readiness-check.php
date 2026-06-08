<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . '/../vendor/autoload.php';

class ReleaseReadinessCheck
{
    /** @var list<string> */
    private array $errors = [];

    public function __construct(
        private readonly string $root,
    ) {
    }

    public function __invoke(): int
    {
        $rootComposer = $this->json('composer.json');
        $workflow = $this->workflow();
        $modules = $this->modules();
        $matrix = $this->splitMatrix($workflow);

        $this->assertRootComposer($rootComposer, $modules);
        $this->assertModuleBranchAliases($modules);
        $this->assertModuleMetadata($modules);
        $this->assertSplitMatrix($modules, $matrix);
        $this->assertWorkflowGate($workflow);

        if ($this->errors === []) {
            fwrite(STDOUT, "Release readiness checks passed.\n");

            return 0;
        }

        fwrite(STDERR, "Release readiness checks failed:\n");

        foreach ($this->errors as $error) {
            fwrite(STDERR, "  - {$error}\n");
        }

        return 1;
    }

    /**
     * @return array<string, array{path: string, composer: array<string, mixed>}>
     */
    private function modules(): array
    {
        $modules = [];

        foreach (glob($this->root . '/src/*/composer.json') ?: [] as $composerPath) {
            $path = dirname($composerPath);
            $module = basename($path);
            $modules[$module] = [
                'path' => str_replace($this->root . '/', '', $path),
                'composer' => $this->json($composerPath),
            ];
        }

        ksort($modules);

        return $modules;
    }

    /**
     * @param array<string, array{path: string, composer: array<string, mixed>}> $modules
     */
    private function assertRootComposer(array $rootComposer, array $modules): void
    {
        $replace = $rootComposer['replace'] ?? [];
        if (!is_array($replace)) {
            $this->errors[] = 'Root composer.json must define replace entries for split packages.';

            return;
        }

        $expectedPackages = $this->modulePackages($modules);
        $actualPackages = array_keys($replace);
        sort($actualPackages);

        if ($actualPackages !== $expectedPackages) {
            $this->errors[] = 'Root replace package set must exactly match module composer packages.';
        }

        foreach ($replace as $package => $version) {
            if ($version !== 'self.version') {
                $this->errors[] = "Root replace entry {$package} must use self.version.";
            }
        }

        $repositories = $rootComposer['repositories'] ?? [];
        if (!is_array($repositories) || !$this->hasPathRepository($repositories, 'src/*')) {
            $this->errors[] = 'Root composer.json must keep a local path repository for src/*.';
        }
    }

    /**
     * @param array<string, array{path: string, composer: array<string, mixed>}> $modules
     */
    private function assertModuleBranchAliases(array $modules): void
    {
        $aliases = [];

        foreach ($modules as $module => $record) {
            $alias = $this->branchAlias($record['composer']);

            if ($alias === null) {
                $this->errors[] = "{$module} branch alias must be defined as MAJOR.MINOR.x-dev.";

                continue;
            }

            $aliases[$alias] = true;
        }

        if (count($aliases) > 1) {
            $this->errors[] = 'Module branch aliases must all use the same release line.';
        }
    }

    /**
     * @param array<string, array{path: string, composer: array<string, mixed>}> $modules
     */
    private function assertModuleMetadata(array $modules): void
    {
        foreach ($modules as $module => $record) {
            $composer = $record['composer'];
            $package = $this->string($composer['name'] ?? null);
            $expectedRepo = 'phalanx-' . substr($package, strlen('phalanx-php/'));
            $expectedUrl = "https://github.com/phalanx-php/{$expectedRepo}";

            if (!str_starts_with($package, 'phalanx-php/')) {
                $this->errors[] = "{$module} package name must use phalanx-php vendor.";
            }

            if (($composer['homepage'] ?? null) !== $expectedUrl) {
                $this->errors[] = "{$module} homepage must be {$expectedUrl}.";
            }

            if (($composer['support']['source'] ?? null) !== $expectedUrl) {
                $this->errors[] = "{$module} support.source must be {$expectedUrl}.";
            }

            $this->assertInterModuleRequires($module, $composer);
        }
    }

    private function assertInterModuleRequires(string $module, array $composer): void
    {
        $expectedConstraint = $this->publishConstraint($composer);
        if ($expectedConstraint === null) {
            return;
        }

        foreach (['require', 'require-dev'] as $section) {
            $requirements = $composer[$section] ?? [];
            if (!is_array($requirements)) {
                continue;
            }

            foreach ($requirements as $package => $constraint) {
                if (!is_string($package) || !str_starts_with($package, 'phalanx-php/')) {
                    continue;
                }

                if ($constraint !== $expectedConstraint) {
                    $this->errors[] = "{$module} {$section}.{$package} must use {$expectedConstraint} for publish metadata.";
                }
            }
        }
    }

    /**
     * @param array<string, array{path: string, composer: array<string, mixed>}> $modules
     * @param array<string, string> $matrix
     */
    private function assertSplitMatrix(array $modules, array $matrix): void
    {
        $expected = [];

        foreach ($modules as $record) {
            $package = $this->string($record['composer']['name'] ?? null);
            $expected[$record['path']] = 'phalanx-' . substr($package, strlen('phalanx-php/'));
        }

        ksort($expected);
        ksort($matrix);

        if ($matrix !== $expected) {
            $this->errors[] = 'Split workflow matrix must exactly match module paths and repository names.';
        }
    }

    /**
     * @param array<string, mixed> $workflow
     */
    private function assertWorkflowGate(array $workflow): void
    {
        $on = $workflow['on'] ?? null;
        if (!is_array($on) || array_keys($on) !== ['workflow_dispatch']) {
            $this->errors[] = 'Split workflow must only run from workflow_dispatch.';
        }

        $jobs = $workflow['jobs'] ?? [];
        if (!is_array($jobs)) {
            $this->errors[] = 'Split workflow must define jobs.';

            return;
        }

        $readiness = $jobs['readiness'] ?? null;
        $split = $jobs['split'] ?? null;
        if (!is_array($readiness)) {
            $this->errors[] = 'Split workflow must define a readiness job.';
        } elseif (!$this->jobHasRunStep($readiness, 'composer release:check')) {
            $this->errors[] = 'Split workflow readiness job must run composer release:check.';
        }

        if (!is_array($split)) {
            $this->errors[] = 'Split workflow must define a split job.';

            return;
        }

        if (($split['needs'] ?? null) !== 'readiness') {
            $this->errors[] = 'Split job must depend on readiness.';
        }

        if (($split['if'] ?? null) !== "github.event.inputs.action == 'split'") {
            $this->errors[] = 'Split job must be gated by action == split.';
        }

        $this->assertSplitMutationSteps($split);
    }

    /**
     * @param array<string, mixed> $workflow
     * @return array<string, string>
     */
    private function splitMatrix(array $workflow): array
    {
        $packages = $workflow['jobs']['split']['strategy']['matrix']['package'] ?? [];
        if (!is_array($packages)) {
            $this->errors[] = 'Split workflow matrix must define package rows.';

            return [];
        }

        $matrix = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $localPath = $package['local_path'] ?? null;
            $splitRepository = $package['split_repository'] ?? null;
            if (is_string($localPath) && is_string($splitRepository)) {
                $matrix[$localPath] = $splitRepository;
            }
        }

        return $matrix;
    }

    /**
     * @param array<string, array{path: string, composer: array<string, mixed>}> $modules
     * @return list<string>
     */
    private function modulePackages(array $modules): array
    {
        $packages = array_map(
            static fn(array $record): string => (string) $record['composer']['name'],
            $modules,
        );

        sort($packages);

        return $packages;
    }

    private function hasPathRepository(array $repositories, string $url): bool
    {
        foreach ($repositories as $repository) {
            if (
                is_array($repository)
                && ($repository['type'] ?? null) === 'path'
                && ($repository['url'] ?? null) === $url
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $path): array
    {
        $fullPath = str_starts_with($path, '/') ? $path : $this->root . '/' . $path;
        $json = json_decode($this->readPath($fullPath), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($json)) {
            throw new RuntimeException("JSON file did not decode to an object: {$path}");
        }

        return $json;
    }

    private function read(string $path): string
    {
        return $this->readPath($this->root . '/' . ltrim($path, '/'));
    }

    private function readPath(string $path): string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException("Unable to read {$path}");
        }

        return $contents;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        $workflow = Yaml::parseFile($this->root . '/.github/workflows/split_modules.yaml');
        if (!is_array($workflow)) {
            throw new RuntimeException('Split workflow did not decode to an object.');
        }

        return $workflow;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function jobHasRunStep(array $job, string $run): bool
    {
        $steps = $job['steps'] ?? [];
        if (!is_array($steps)) {
            return false;
        }

        foreach ($steps as $step) {
            if (is_array($step) && ($step['run'] ?? null) === $run) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $split
     */
    private function assertSplitMutationSteps(array $split): void
    {
        $steps = $split['steps'] ?? [];
        if (!is_array($steps)) {
            $this->errors[] = 'Split job must define steps.';

            return;
        }

        $confirmationIndex = null;
        $mutationIndexes = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                continue;
            }

            $name = $step['name'] ?? null;
            if ($name === 'Require explicit split confirmation') {
                $confirmationIndex = $index;
                if (!is_string($step['run'] ?? null) || !str_contains($step['run'], 'SPLIT PHALANX PACKAGES')) {
                    $this->errors[] = 'Split confirmation step must require the exact confirmation phrase.';
                }
            }

            if ($name === 'Ensure split repositories exist' || $name === 'Split module') {
                $mutationIndexes[] = $index;
                if (($step['if'] ?? null) !== "github.event.inputs.action == 'split'") {
                    $this->errors[] = "{$name} step must be gated by action == split.";
                }
            }
        }

        if ($confirmationIndex === null) {
            $this->errors[] = 'Split job must require explicit confirmation before mutation.';

            return;
        }

        foreach ($mutationIndexes as $index) {
            if ($confirmationIndex > $index) {
                $this->errors[] = 'Split confirmation step must run before all mutation steps.';
            }
        }
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function branchAlias(array $composer): ?string
    {
        $alias = $composer['extra']['branch-alias']['dev-main'] ?? null;
        if (!is_string($alias) || preg_match('/^\d+\.\d+\.x-dev$/', $alias) !== 1) {
            return null;
        }

        return $alias;
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function publishConstraint(array $composer): ?string
    {
        $alias = $this->branchAlias($composer);
        if ($alias === null) {
            return null;
        }

        return '^' . str_replace('.x-dev', '', $alias);
    }
}

exit((new ReleaseReadinessCheck(releaseReadinessRoot($argv)))());

/** @param list<string> $argv */
function releaseReadinessRoot(array $argv): string
{
    $root = dirname(__DIR__);

    foreach ($argv as $index => $argument) {
        if ($argument === '--root' && isset($argv[$index + 1])) {
            $root = $argv[$index + 1];
        }
    }

    return $root;
}
