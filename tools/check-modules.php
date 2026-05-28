#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/module-manifest.php';

$root = dirname(__DIR__);
$modules = require $root . '/modules.php';
$rootComposer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$errors = [];
$packages = [];
$allPackages = array_column($modules, 'package');
$publishedPackages = array_column(array_filter($modules, phalanx_module_is_published(...)), 'package');

foreach ($modules as $module => $meta) {
    $path = $root . '/src/' . $module;
    $composerPath = $path . '/composer.json';
    $published = phalanx_module_is_published($meta);

    if (! is_dir($path)) {
        $errors[] = "$module: missing src/$module directory";
        continue;
    }

    if (! is_file($composerPath)) {
        $errors[] = "$module: missing composer.json";
        continue;
    }

    foreach (['phpstan.neon', 'phpcs.xml', 'rector.php'] as $localConfig) {
        if (is_file($path . '/' . $localConfig)) {
            $errors[] = "$module: $localConfig belongs at the framework root";
        }
    }

    $composer = json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);
    $package = $meta['package'];
    $testNamespaces = $meta['testNamespaces'] ?? [$meta['testNamespace'] => 'tests/'];

    if ($published) {
        $packages[$package] = $module;

        if (phalanx_normalized_manifest($composer) !== phalanx_normalized_manifest(phalanx_module_manifest($module, $meta))) {
            $errors[] = "$module: composer.json does not match generated module manifest";
        }

        if (! is_file($path . '/LICENSE')) {
            $errors[] = "$module: missing LICENSE for split package";
        }

        expect($errors, $module, 'root replace', 'self.version', $rootComposer['replace'][$package] ?? null);
    } elseif (isset($rootComposer['replace'][$package])) {
        $errors[] = "$module: non-published roadmap module must not be listed in root replace";
    }

    expect($errors, $module, 'root autoload', 'src/' . $module . '/src/', $rootComposer['autoload']['psr-4'][$meta['namespace']] ?? null);
    foreach ($testNamespaces as $namespace => $path) {
        expect($errors, $module, 'root test autoload ' . $namespace, 'src/' . $module . '/' . $path, $rootComposer['autoload-dev']['psr-4'][$namespace] ?? null);
    }

    if ($published) {
        foreach (array_keys($meta['requires']) as $dependency) {
            if (in_array($dependency, $allPackages, true) && ! in_array($dependency, $publishedPackages, true)) {
                $errors[] = "{$module}: published module requires non-published module {$dependency}";
            }
        }
    }
}

foreach (array_keys($rootComposer['replace'] ?? []) as $package) {
    if (str_starts_with($package, 'phalanx-php/') && ! isset($packages[$package])) {
        $errors[] = "root replace contains unknown package $package";
    }
}

foreach (graphErrors(array_filter($modules, phalanx_module_is_published(...))) as $error) {
    $errors[] = $error;
}

$legacySplitAction = 'symplify/' . 'mono' . 'repo-split-github-action';
$workflow = is_file($root . '/.github/workflows/split_modules.yaml')
    ? (string) file_get_contents($root . '/.github/workflows/split_modules.yaml')
    : '';

if ($workflow === '') {
    $errors[] = 'missing .github/workflows/split_modules.yaml';
} elseif (str_contains($workflow, $legacySplitAction)) {
    $errors[] = 'split workflow must not use the legacy subtree split action';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

$publishedCount = count(array_filter($modules, phalanx_module_is_published(...)));
$roadmapCount = count($modules) - $publishedCount;

echo sprintf("Module metadata OK: %d published, %d non-published modules\n", $publishedCount, $roadmapCount);

function expect(array &$errors, string $module, string $field, mixed $expected, mixed $actual): void
{
    if ($expected === $actual) {
        return;
    }

    $errors[] = sprintf(
        '%s: %s mismatch; expected %s, got %s',
        $module,
        $field,
        json_encode($expected, JSON_UNESCAPED_SLASHES),
        json_encode($actual, JSON_UNESCAPED_SLASHES),
    );
}

function graphErrors(array $modules): array
{
    $moduleByPackage = [];

    foreach ($modules as $module => $meta) {
        $moduleByPackage[$meta['package']] = $module;
    }

    $graph = [];
    $errors = [];

    foreach ($modules as $module => $meta) {
        $graph[$module] = [];

        foreach (array_keys($meta['requires']) as $dependency) {
            if (isset($moduleByPackage[$dependency])) {
                $graph[$module][] = $moduleByPackage[$dependency];
            }
        }
    }

    $states = [];

    foreach ($graph as $module => $_) {
        visit($module, $graph, $states, $errors);
    }

    return array_values(array_unique($errors));
}

function visit(string $module, array $graph, array &$states, array &$errors): void
{
    if (($states[$module] ?? null) === 'done') {
        return;
    }

    if (($states[$module] ?? null) === 'visiting') {
        $errors[] = 'dependency cycle reaches ' . $module;
        return;
    }

    $states[$module] = 'visiting';

    foreach ($graph[$module] ?? [] as $dependency) {
        visit($dependency, $graph, $states, $errors);
    }

    $states[$module] = 'done';
}
