#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$modules = require $root . '/modules.php';
$module = optionValue($argv, '--module') ?? 'Aegis';
$keep = in_array('--keep', $argv, true);

if (!isset($modules[$module])) {
    fwrite(STDERR, "Unknown module: {$module}\n");
    exit(1);
}

$package = $modules[$module]['package'];
$fixture = sys_get_temp_dir() . '/phalanx-install-proof-' . strtolower($module) . '-' . getmypid();

if (is_dir($fixture)) {
    removeTree($fixture);
}

mkdir($fixture, 0777, true);

try {
    file_put_contents(
        $fixture . '/composer.json',
        json_encode(fixtureComposer($package, $root, $module), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    );

    file_put_contents($fixture . '/smoke.php', smokeScript());

    run(['composer', 'install', '--no-interaction', '--no-progress'], $fixture);
    run([PHP_BINARY, 'smoke.php'], $fixture);

    printf("Independent install proof OK: %s from src/%s\n", $package, $module);
} finally {
    if ($keep) {
        printf("Kept proof fixture: %s\n", $fixture);
    } else {
        removeTree($fixture);
    }
}

function optionValue(array $argv, string $name): ?string
{
    foreach ($argv as $index => $arg) {
        if ($arg === $name && isset($argv[$index + 1])) {
            return $argv[$index + 1];
        }
    }

    return null;
}

function fixtureComposer(string $package, string $root, string $module): array
{
    return [
        'name' => 'phalanx-php/module-install-proof',
        'type' => 'project',
        'require' => [
            'php' => '^8.4',
            $package => '*',
        ],
        'repositories' => [
            [
                'type' => 'path',
                'url' => $root . '/src/' . $module,
                'options' => [
                    'symlink' => false,
                ],
            ],
        ],
        'minimum-stability' => 'dev',
        'prefer-stable' => true,
        'config' => [
            'sort-packages' => true,
            'allow-plugins' => [
                'dealerdirect/phpcodesniffer-composer-installer' => true,
            ],
        ],
    ];
}

function smokeScript(): string
{
    return <<<'PHP'
        <?php

        declare(strict_types=1);

        require __DIR__ . '/vendor/autoload.php';

        $policy = Phalanx\Runtime\RuntimePolicy::phalanxManaged();

        if ($policy->name === '') {
            fwrite(STDERR, "Runtime policy did not initialize.\n");
            exit(1);
        }

        echo "Aegis autoload smoke OK: {$policy->name}\n";
        PHP;
}

function run(array $command, string $cwd): void
{
    $display = implode(' ', array_map('escapeshellarg', $command));
    $descriptorSpec = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);

    if (!is_resource($process)) {
        fwrite(STDERR, "Failed to start command: {$display}\n");
        exit(1);
    }

    $exitCode = proc_close($process);

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
