#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$modules = require $root . '/modules.php';
$moduleFilter = optionValue($argv, '--module');
$targets = $moduleFilter === null ? array_keys($modules) : [$moduleFilter];

foreach ($targets as $module) {
    if (!isset($modules[$module])) {
        fwrite(STDERR, "Unknown module: {$module}\n");
        exit(1);
    }

    run([PHP_BINARY, $root . '/tools/split-modules.php', '--verify-only', '--module', $module], $root);
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
function run(array $command, string $cwd): void
{
    $display = implode(' ', array_map('escapeshellarg', $command));
    passthru('cd ' . escapeshellarg($cwd) . ' && ' . $display, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "Command failed ({$exitCode}): {$display}\n");
        exit($exitCode);
    }
}
