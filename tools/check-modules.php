#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$modules = require $root . '/modules.php';
$rootComposer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$errors = [];
$packages = [];

foreach ($modules as $module => $meta) {
    $path = $root . '/src/' . $module;
    $composerPath = $path . '/composer.json';

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
    $packages[$package] = $module;

    expect($errors, $module, 'name', $package, $composer['name'] ?? null);
    expect($errors, $module, 'description', $meta['description'], $composer['description'] ?? null);
    expect($errors, $module, 'type', $meta['type'], $composer['type'] ?? null);
    expect($errors, $module, 'require', $meta['requires'], $composer['require'] ?? []);
    expect($errors, $module, 'require-dev', $meta['devRequires'], $composer['require-dev'] ?? []);
    expect($errors, $module, 'bin', $meta['bins'], $composer['bin'] ?? []);
    expect($errors, $module, 'branch alias', $meta['branchAlias'], $composer['extra']['branch-alias']['dev-main'] ?? null);
    $testNamespaces = $meta['testNamespaces'] ?? [$meta['testNamespace'] => 'tests/'];

    expect($errors, $module, 'autoload namespace', [$meta['namespace'] => 'src/'], $composer['autoload']['psr-4'] ?? []);
    expect($errors, $module, 'test namespace', $testNamespaces, $composer['autoload-dev']['psr-4'] ?? []);
    expect($errors, $module, 'scripts', ['test' => packageTestScript($module)], $composer['scripts'] ?? []);

    if (($meta['autoloadFiles'] ?? []) !== ($composer['autoload']['files'] ?? [])) {
        expect($errors, $module, 'autoload files', $meta['autoloadFiles'] ?? [], $composer['autoload']['files'] ?? []);
    }

    expect($errors, $module, 'root replace', 'self.version', $rootComposer['replace'][$package] ?? null);
    expect($errors, $module, 'root autoload', 'src/' . $module . '/src/', $rootComposer['autoload']['psr-4'][$meta['namespace']] ?? null);
    foreach ($testNamespaces as $namespace => $path) {
        expect($errors, $module, 'root test autoload ' . $namespace, 'src/' . $module . '/' . $path, $rootComposer['autoload-dev']['psr-4'][$namespace] ?? null);
    }
}

foreach (array_keys($rootComposer['replace'] ?? []) as $package) {
    if (str_starts_with($package, 'phalanx-php/') && ! isset($packages[$package])) {
        $errors[] = "root replace contains unknown package $package";
    }
}

foreach (graphErrors($modules) as $error) {
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

echo sprintf("Module metadata OK: %d modules\n", count($modules));

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

function packageTestScript(string $module): string
{
    $config = is_file(__DIR__ . '/../src/' . $module . '/phpunit.xml.dist')
        ? 'phpunit.xml.dist'
        : 'phpunit.xml';

    return 'php -d memory_limit=512M ../../vendor/bin/phpunit -c ' . $config;
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

    foreach ($graph as $module => $_) {
        visit($module, $graph, [], [], $errors);
    }

    return array_values(array_unique($errors));
}

function visit(string $module, array $graph, array $visiting, array $visited, array &$errors): void
{
    if (isset($visited[$module])) {
        return;
    }

    if (isset($visiting[$module])) {
        $errors[] = 'dependency cycle reaches ' . $module;
        return;
    }

    $visiting[$module] = true;

    foreach ($graph[$module] ?? [] as $dependency) {
        visit($dependency, $graph, $visiting, $visited, $errors);
    }
}
