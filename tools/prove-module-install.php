#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php. Run composer install before the proof tool.\n");
    exit(1);
}

require $autoload;
require __DIR__ . '/module-manifest.php';

$modules = require $root . '/modules.php';
$publishedModules = array_filter(
    $modules,
    phalanx_module_is_published(...),
);
$targets = in_array('--all', $argv, true)
    ? array_keys($publishedModules)
    : [phalanx_option_value($argv, '--module') ?? 'Aegis'];
$keep = in_array('--keep', $argv, true);

foreach ($targets as $module) {
    proveModule($module, $modules, $root, $keep);
}

function proveModule(string $module, array $modules, string $root, bool $keep): void
{
    if (!isset($modules[$module])) {
        fwrite(STDERR, "Unknown module: {$module}\n");
        exit(1);
    }

    if (! phalanx_module_is_published($modules[$module])) {
        fwrite(STDERR, "Module is not configured for split publishing: {$module}\n");
        exit(1);
    }

    $package = $modules[$module]['package'];
    $smokeClass = $modules[$module]['smokeClass'] ?? null;
    $fixture = sys_get_temp_dir() . '/' . uniqid('phalanx-install-proof-' . strtolower($module) . '-', true);

    if (!is_string($smokeClass) || $smokeClass === '') {
        fwrite(STDERR, "Missing smokeClass metadata for module: {$module}\n");
        exit(1);
    }

    if (!mkdir($fixture, 0777, true) && !is_dir($fixture)) {
        fwrite(STDERR, "Failed to create proof fixture: {$fixture}\n");
        exit(1);
    }

    try {
        file_put_contents(
            $fixture . '/composer.json',
            json_encode(
                fixtureComposer($package, $modules, $root),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );

        file_put_contents($fixture . '/smoke.php', smokeScript($module, $smokeClass));

        run([composerBinary(), 'install', '--no-interaction', '--no-progress', '--ignore-platform-req=ext-swoole'], $fixture, 300);
        run([PHP_BINARY, 'smoke.php'], $fixture, 30);

        printf("Independent install proof OK: %s from src/%s\n", $package, $module);
    } finally {
        if ($keep) {
            printf("Kept proof fixture: %s\n", $fixture);
        } else {
            removeTree($fixture);
        }
    }
}

function composerBinary(): string
{
    $binary = getenv('COMPOSER_BINARY');

    return is_string($binary) && $binary !== '' ? $binary : 'composer';
}

function fixtureComposer(string $package, array $modules, string $root): array
{
    return [
        'name' => 'phalanx-php/module-install-proof',
        'type' => 'project',
        'require' => [
            'php' => '^8.4',
            $package => '*',
        ],
        'repositories' => pathRepositories($modules, $root),
        'minimum-stability' => 'dev',
        'prefer-stable' => true,
        'config' => [
            'sort-packages' => true,
            'allow-plugins' => allowPlugins($modules),
        ],
    ];
}

function allowPlugins(array $modules): array
{
    $allowPlugins = [
        'dealerdirect/phpcodesniffer-composer-installer' => true,
    ];

    foreach ($modules as $meta) {
        foreach (($meta['allowPlugins'] ?? []) as $plugin => $allowed) {
            $allowPlugins[$plugin] = $allowed;
        }
    }

    ksort($allowPlugins);

    return $allowPlugins;
}

function pathRepositories(array $modules, string $root): array
{
    $repositories = [];

    foreach (array_keys($modules) as $module) {
        $repositories[] = [
            'type' => 'path',
            'url' => $root . '/src/' . $module,
            'options' => [
                'symlink' => false,
            ],
        ];
    }

    return $repositories;
}

function smokeScript(string $module, string $smokeClass): string
{
    $moduleLiteral = var_export($module, true);
    $classLiteral = var_export($smokeClass, true);

    return <<<PHP
        <?php

        declare(strict_types=1);

        require __DIR__ . '/vendor/autoload.php';

        \$module = {$moduleLiteral};
        \$smokeClass = {$classLiteral};

        \$exists = class_exists(\$smokeClass)
            || interface_exists(\$smokeClass)
            || enum_exists(\$smokeClass)
            || trait_exists(\$smokeClass);

        if (!\$exists) {
            fwrite(STDERR, "Smoke class did not autoload: {\$smokeClass}\n");
            exit(1);
        }

        if (\$smokeClass === Phalanx\Runtime\RuntimePolicy::class) {
            \$policy = Phalanx\Runtime\RuntimePolicy::phalanxManaged();

            if (\$policy->name === '') {
                fwrite(STDERR, "Runtime policy did not initialize.\n");
                exit(1);
            }

            echo "Aegis autoload smoke OK: {\$policy->name}\n";
            exit(0);
        }

        echo "{\$module} autoload smoke OK: {\$smokeClass}\n";
        PHP;
}

function run(array $command, string $cwd, float $timeout): void
{
    $display = implode(' ', array_map('escapeshellarg', $command));
    $process = new Process(
        command: $command,
        cwd: $cwd,
        timeout: $timeout,
    );

    $exitCode = $process->run(static function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    });

    if ($exitCode !== 0) {
        fwrite(STDERR, "Command failed ({$exitCode}): {$display}\n");
        exit($exitCode);
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}
