#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/module-manifest.php';

$root = dirname(__DIR__);
$modules = require $root . '/modules.php';
$moduleFilter = phalanx_option_value($argv, '--module');
$publishedModules = array_filter(
    $modules,
    phalanx_module_is_published(...),
);
$targets = $moduleFilter === null ? array_keys($publishedModules) : [$moduleFilter];

foreach ($targets as $module) {
    if (!isset($modules[$module])) {
        fwrite(STDERR, "Unknown module: {$module}\n");
        exit(1);
    }

    if (! phalanx_module_is_published($modules[$module])) {
        fwrite(STDERR, "Module is not configured for split publishing: {$module}\n");
        exit(1);
    }

    run([PHP_BINARY, $root . '/tools/split-modules.php', '--verify-only', '--module', $module], $root);
}

function run(array $command, string $cwd): void
{
    $display = implode(' ', array_map('escapeshellarg', $command));
    passthru('cd ' . escapeshellarg($cwd) . ' && ' . $display, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "Command failed ({$exitCode}): {$display}\n");
        exit($exitCode);
    }
}
