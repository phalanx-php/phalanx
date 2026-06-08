<?php

declare(strict_types=1);

final class ReleaseReadinessCheck
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
        $modules = $this->modules();
        $matrix = $this->splitMatrix();

        $this->assertRootComposer($rootComposer, $modules);
        $this->assertModuleMetadata($modules);
        $this->assertSplitMatrix($modules, $matrix);
        $this->assertWorkflowGate();

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

            if (($composer['extra']['branch-alias']['dev-main'] ?? null) !== '0.7.x-dev') {
                $this->errors[] = "{$module} branch alias must be 0.7.x-dev.";
            }

            $this->assertInterModuleRequires($module, $composer);
        }
    }

    private function assertInterModuleRequires(string $module, array $composer): void
    {
        foreach (['require', 'require-dev'] as $section) {
            $requirements = $composer[$section] ?? [];
            if (!is_array($requirements)) {
                continue;
            }

            foreach ($requirements as $package => $constraint) {
                if (!is_string($package) || !str_starts_with($package, 'phalanx-php/')) {
                    continue;
                }

                if ($constraint !== '^0.7') {
                    $this->errors[] = "{$module} {$section}.{$package} must use ^0.7 for publish metadata.";
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

    private function assertWorkflowGate(): void
    {
        $workflow = $this->read('.github/workflows/split_modules.yaml');

        if (preg_match('/^\s*push\s*:/m', $workflow) === 1) {
            $this->errors[] = 'Split workflow must not run from push or tag events.';
        }

        foreach (['workflow_dispatch:', 'inputs:', 'action:', 'confirmation:', 'SPLIT PHALANX PACKAGES'] as $token) {
            if (!str_contains($workflow, $token)) {
                $this->errors[] = "Split workflow is missing manual gate token: {$token}";
            }
        }

        if (!str_contains($workflow, "github.event.inputs.action == 'split'")) {
            $this->errors[] = 'Split workflow mutation steps must be gated by action == split.';
        }

        if (!str_contains($workflow, 'composer release:check')) {
            $this->errors[] = 'Split workflow readiness job must run composer release:check.';
        }
    }

    /**
     * @return array<string, string>
     */
    private function splitMatrix(): array
    {
        $workflow = $this->read('.github/workflows/split_modules.yaml');
        preg_match_all(
            "/local_path:\\s*'([^']+)'\\s*,\\s*split_repository:\\s*'([^']+)'/",
            $workflow,
            $matches,
            PREG_SET_ORDER,
        );

        $matrix = [];

        foreach ($matches as $match) {
            $matrix[$match[1]] = $match[2];
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
}

exit((new ReleaseReadinessCheck(dirname(__DIR__)))());
