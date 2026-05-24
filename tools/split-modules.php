#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$modules = require $root . '/modules.php';
$dryRun = in_array('--dry-run', $argv, true);
$owner = getenv('SPLIT_REPOSITORY_OWNER') ?: 'phalanx-php';
$token = getenv('GH_ACCESS_TOKEN') ?: getenv('GITHUB_TOKEN') ?: '';
$refName = getenv('GITHUB_REF_TYPE') === 'tag' ? getenv('GITHUB_REF_NAME') : '';

chdir($root);

foreach ($modules as $module => $meta) {
    $repository = repositoryName($meta['package']);
    $branch = 'split/' . packageSlug($meta['package']);
    $prefix = 'src/' . $module;

    run(['git', 'subtree', 'split', '--prefix', $prefix, '-b', $branch], $dryRun);

    $target = $token === ''
        ? sprintf('git@github.com:%s/%s.git', $owner, $repository)
        : sprintf('https://x-access-token:%s@github.com/%s/%s.git', $token, $owner, $repository);

    run(['git', 'push', $target, $branch . ':main', '--force'], $dryRun, $token);

    if (is_string($refName) && $refName !== '') {
        run(['git', 'push', $target, $branch . ':refs/tags/' . $refName, '--force'], $dryRun, $token);
    }
}

function repositoryName(string $package): string
{
    $name = packageSlug($package);

    return 'phalanx-' . $name;
}

function packageSlug(string $package): string
{
    [, $name] = explode('/', $package, 2);

    return $name;
}

function run(array $command, bool $dryRun, string $redact = ''): void
{
    $display = implode(' ', array_map('escapeshellarg', $command));

    if ($redact !== '') {
        $display = str_replace($redact, '***', $display);
    }

    if ($dryRun) {
        echo $display . PHP_EOL;
        return;
    }

    passthru($display, $exitCode);

    if ($exitCode !== 0) {
        exit($exitCode);
    }
}
